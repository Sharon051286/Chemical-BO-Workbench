#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
MNL-BO 引擎 — 基于论文
"Bayesian Optimization for Categorical and Mixed Variables Using a
 Multinomial Logit Surrogate" (Algorithms 2026, 19, 361)

与 EDBO / Ax 两个 GP 引擎的本质区别在 *surrogate 模型*：
  - GP 引擎：高斯过程后验（贝叶斯），类别需编码进数值特征空间。
  - MNL-BO ：多项 Logit（MNL）替代 GP，频率派 MLE 拟合，根植于随机效用理论。
             类别变量通过哑变量（ohe）进入线性效用，天然无顺序/距离假设。

本脚本接口与 edbo_runner.py 完全同构（同样的 --config / --output 契约，
同样的输出 JSON 键），这样前端（best / convergence / experiments /
predicted_next）无需任何改动即可渲染 MNL 引擎的结果。

MNL-BO 核心（论文 2.1–2.8）：
  1. 从已评估档案构造成对偏好 (winner ≻ loser)，winner 为观测目标更高者。
  2. 成对 MNL 似然等价于在「特征差 d = φ(x_w) − φ(x_l)」上做 logistic 回归，
     标签恒为 1：Pr(w≻l) = σ(βᵀd)。β 由 MLE 用牛顿/IRWLS 求得。
  3. 预测：µ(x) = φ(x)ᵀβ̂ ；不确定度 σ(x) = √(φ(x)ᵀ Σ̂β φ(x))（delta 法，
     Σ̂β 取负 Hessian 逆，加 ridge 保证可逆）。
  4. 选择概率 π(x) = softmax_x′ exp(µ(x′))（全域）。
  5. 采集函数（论文 2.6）：
       EI_MNL(x)  = max(µ(x) − µ⁺, 0) · π(x)
       UCB_MNL(x) = µ(x) + κ·σ(x)·π(x) ，κ = 2
     其中 µ⁺ = 已评估候选中的最大效用（同一量纲，避免论文把 µ 与 f 混用导致的尺度错配）。
  6. 稳健性（论文 2.3）：若 MNL 拟合因分离/不可辨识/近奇异 Hessian 失败，
     该轮退化为均匀随机候选选择。

说明：MNL 直接给出的是「效用/排名」而非「产率」预测。为了让前端 predicted_yield
仍是有意义的产率数值，我们用已评估点的 (µ, y) 做一次线性校准 y ≈ a·µ + b，
把候选效用映射到产率尺度（与 edbo_runner 用 GP 直接预测产率类比）。
"""

import sys
import os
import json
import warnings
import argparse
from datetime import datetime

import numpy as np
import pandas as pd

# 复用 EDBO 引擎的特征空间构造，保证编码口径一致（ohe 对类别无顺序假设）
from edbo_runner import (
    build_feature_space,
    restore_original_params,
    build_prior_results,
    build_synthetic_objective,
)

warnings.filterwarnings('ignore')
import logging
logging.basicConfig(level=logging.ERROR)


# --------------------------------------------------------------------------- #
# MNL surrogate：成对偏好 logistic 回归 MLE
# --------------------------------------------------------------------------- #
def fit_mnl_beta(Phi_diff, ridge=1e-3, max_iter=100, tol=1e-8):
    """在特征差上做 logistic 回归 MLE，返回 (β, Covβ)。

    Phi_diff : (M, d) 每个样本是 φ(x_w) − φ(x_l)，标签恒为 1（winner 更优）。
    返回 β (d,) 与协方差 Σ̂β = (Σ s(1−s) d dᵀ + λI)⁻¹（delta 法不确定度）。
    若矩阵奇异（分离/不可辨识），抛出 np.linalg.LinAlgError 由调用方回落随机。
    """
    M, d = Phi_diff.shape
    beta = np.zeros(d)
    eye = np.eye(d)
    for _ in range(max_iter):
        z = Phi_diff @ beta
        s = 1.0 / (1.0 + np.exp(-z))
        # 梯度：∂log σ(z)/∂β = (1 − s)·d  （标签=1）
        grad = Phi_diff.T @ (1.0 - s)
        # Hessian = −Σ s(1−s) d dᵀ  →  A = Σ s(1−s) d dᵀ + λI 用于 Newton 步与 Cov
        w = s * (1.0 - s)
        A = (Phi_diff * w[:, None]).T @ Phi_diff + ridge * eye
        try:
            step = np.linalg.solve(A, grad)
        except np.linalg.LinAlgError:
            raise
        beta = beta + step
        if np.max(np.abs(step)) < tol:
            break
    # 收尾再算一次 Cov（用收敛后的 s）
    z = Phi_diff @ beta
    s = 1.0 / (1.0 + np.exp(-z))
    w = s * (1.0 - s)
    A = (Phi_diff * w[:, None]).T @ Phi_diff + ridge * eye
    cov = np.linalg.inv(A)
    return beta, cov


def predict_utility(Phi, beta):
    return Phi @ beta


def predict_unc(Phi, cov, eps=1e-6):
    var = np.einsum('ij,jk,ik->i', Phi, cov, Phi)
    var = np.clip(var, eps, None)
    return np.sqrt(var)


def softmax(x):
    x = x - np.max(x)
    e = np.exp(x)
    return e / e.sum()


# --------------------------------------------------------------------------- #
# 主流程
# --------------------------------------------------------------------------- #
class NumpyEncoder(json.JSONEncoder):
    def default(self, obj):
        if isinstance(obj, np.integer):
            return int(obj)
        if isinstance(obj, np.floating):
            return float(obj)
        if isinstance(obj, np.ndarray):
            return obj.tolist()
        return super().default(obj)


def _mnl_prior_experiments(prior_df, names, target):
    """把先验真实记录整理为 experiments 列表（batch=0 表示已做实验）。"""
    experiments = []
    for _, r in prior_df.iterrows():
        item = {name: r[name] for name in names}
        item[target] = round(float(r[target]), 3)
        item['batch'] = 0
        experiments.append(item)
    return experiments


def _mnl_combo_close(a, b, parameters):
    for p in parameters:
        name = p['name']
        if name not in a or name not in b:
            return False
        enc = p.get('encoding', 'numeric')
        vals = p.get('values') or []
        is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)
        va, vb = a[name], b[name]
        if enc == 'numeric' and (not is_cat) and len(vals) >= 2:
            try:
                fa, fb = float(va), float(vb)
            except (TypeError, ValueError):
                return False
            lo, hi = float(min(vals)), float(max(vals))
            span = abs(hi - lo) if hi != lo else 1.0
            if abs(fa - fb) > max(0.05, 0.01 * span):
                return False
        elif str(va).strip() != str(vb).strip():
            return False
    return True


def _mnl_mark_used_indices(domain, names, parameters, n_domain, evaluated, config):
    """把本课题已推荐/已评估的组合从候选里剔除。"""
    used = config.get('used_combinations') or []
    if not isinstance(used, list) or not used:
        return evaluated
    taken = set(evaluated)
    for i in range(n_domain):
        if i in taken:
            continue
        cand = {name: domain.loc[i, name] for name in names}
        for u in used:
            if isinstance(u, dict) and _mnl_combo_close(cand, u, parameters):
                taken.add(i)
                break
    return sorted(taken)


def _mnl_best(prior_df, names, target):
    """从先验真实记录中取目标最大的一条作为当前最优。"""
    best_idx = prior_df[target].idxmax()
    best_row = prior_df.loc[best_idx]
    best = {name: best_row[name] for name in names}
    best[target] = round(float(best_row[target]), 3)
    return best


def _mnl_recommend(config, parameters, names, domain, n_domain, Phi_all, d,
                   feature_columns, reverse_spec, target, batch_size, acq,
                   init_method, iterations, seed, prior_df, has_prior):
    """正式版推荐：基于真实实验数据推荐下一批实验，不做合成评估。

    真实实验迭代流程：
      - 无先验：输出空间填充初始设计（随机互异组合），供用户先跑一批真实实验；
      - 有先验：用真实观测训练 MNL 偏好模型，按采集函数推荐 batch_size 个
        信息量最大的候选；每点附 predicted_<target> 线性校准值，便于判断。
    """
    rng = np.random.default_rng(seed)
    acq_upper = acq.upper()
    use_ucb = 'UCB' in acq_upper
    KAPPA = 2.0

    if not has_prior:
        # 无先验：空间填充初始设计（随机互异组合），并避开本课题已推荐过的点
        blocked = set(_mnl_mark_used_indices(domain, names, parameters, n_domain, [], config))
        pool = [i for i in range(n_domain) if i not in blocked]
        n0 = min(batch_size, len(pool))
        selected = list(rng.choice(pool, size=n0, replace=False)) if n0 else []
        selected = list(dict.fromkeys(selected))
        experiments = []
        best = None
        recommended = [{name: domain.loc[idx, name] for name in names} for idx in selected]
        last_beta = last_cov = None
    else:
        # 先验即为已评估档案，真实目标值
        merge_keys = names
        dom_merge = domain.copy()
        dom_merge['__idx'] = np.arange(n_domain)
        merged = prior_df[merge_keys].merge(dom_merge, on=merge_keys, how='inner')
        evaluated = sorted(set(int(i) for i in merged['__idx']))
        evaluated = _mnl_mark_used_indices(domain, names, parameters, n_domain, evaluated, config)

        y_real = np.full(n_domain, np.nan)
        prior_merge = prior_df[merge_keys + [target]].merge(dom_merge, on=merge_keys, how='inner')
        for _, r in prior_merge.iterrows():
            y_real[int(r['__idx'])] = float(r[target])

        uneval = [i for i in range(n_domain) if i not in set(evaluated)]

        fit_ok = False
        last_beta = last_cov = None
        if len(uneval) > 0:
            n_eval = len(evaluated)
            n_pairs_max = min(400, n_eval * (n_eval - 1) // 2)
            pairs = []
            if n_pairs_max > 0:
                cand_i = np.array(evaluated)
                for _ in range(n_pairs_max):
                    a, b = rng.choice(cand_i, size=2, replace=False)
                    ya, yb = y_real[a], y_real[b]
                    if ya == yb:
                        continue
                    w, l = (a, b) if ya > yb else (b, a)
                    pairs.append((w, l))
            if len(pairs) >= max(2, d):
                Phi_diff = Phi_all[[p[0] for p in pairs]] - Phi_all[[p[1] for p in pairs]]
                try:
                    last_beta, last_cov = fit_mnl_beta(Phi_diff)
                    fit_ok = True
                except np.linalg.LinAlgError:
                    fit_ok = False

        if len(uneval) == 0:
            # 全部组合都已有数据，无需额外推荐
            experiments = _mnl_prior_experiments(prior_df, names, target)
            best = _mnl_best(prior_df, names, target)
            recommended = []
            return _mnl_recommend_output(
                config, 'mnl', target, n_domain, names, batch_size, acq,
                'external', iterations, prior_df, experiments, best, recommended,
            )

        if not fit_ok:
            # 稳健兜底：均匀随机选
            nk = min(batch_size, len(uneval))
            selected = list(rng.choice(uneval, size=nk, replace=False))
        else:
            mu_all = predict_utility(Phi_all, last_beta)
            sig_all = predict_unc(Phi_all, last_cov)
            pi_all = softmax(mu_all)
            mu_best = float(np.max(mu_all[evaluated]))
            if use_ucb:
                acq_all = mu_all + KAPPA * sig_all * pi_all
            else:
                improvement = np.clip(mu_all - mu_best, 0.0, None)
                acq_all = improvement * pi_all
            acq_uneval = acq_all[uneval]
            order = np.argsort(-acq_uneval)[:batch_size]
            selected = [uneval[k] for k in order]

        # 校准到目标尺度：y ≈ a·µ + b
        recommended = []
        if fit_ok and last_cov is not None:
            try:
                mu_eval = predict_utility(Phi_all[evaluated], last_beta)
                y_eval = y_real[evaluated]
                a, b = np.polyfit(mu_eval, y_eval, 1)
                mu_u = predict_utility(Phi_all[selected], last_beta)
                sig_u = predict_unc(Phi_all[selected], last_cov)
                for k, idx in enumerate(selected):
                    item = {name: domain.loc[idx, name] for name in names}
                    item['predicted_' + target] = round(float(a * mu_u[k] + b), 3)
                    item['variance'] = round(float((a ** 2) * (sig_u[k] ** 2)), 3)
                    recommended.append(item)
            except Exception:
                recommended = [{name: domain.loc[idx, name] for name in names} for idx in selected]
        else:
            recommended = [{name: domain.loc[idx, name] for name in names} for idx in selected]

        experiments = _mnl_prior_experiments(prior_df, names, target)
        best = _mnl_best(prior_df, names, target)

    return _mnl_recommend_output(
        config, 'mnl', target, n_domain, names, batch_size, acq,
        'external' if has_prior else init_method, iterations, prior_df,
        experiments, best, recommended,
    )


def _mnl_recommend_output(config, engine, target, n_domain, names, batch_size,
                         acq, init_method, iterations, prior_df, experiments,
                         best, recommended):
    """组装正式版推荐结果 dict。"""
    return {
        'status': 'success',
        'mode': 'recommend',
        'engine': engine,
        'target': target,
        'domain_size': int(n_domain),
        'parameter_names': names,
        'batch_size': batch_size,
        'acquisition_function': acq,
        'init_method': init_method,
        'iterations': iterations,
        'prior_results_count': int(len(prior_df)) if len(prior_df) > 0 else 0,
        'total_evaluations': int(len(experiments)),
        'best': best,
        'convergence': [],
        'experiments': experiments,
        'predicted_next': recommended,
        'recommended_experiments': recommended,
        'finished_at': datetime.now().isoformat(),
    }


def run_config(config):
    """核心 MNL-BO 流程：接受配置 dict，返回结果 dict。

    抽出来后既可被 CLI 入口 run() 调用，也可被对照实验脚本直接调用
    （传入注入的 y_all / 初始索引，保证与 GP 基线实验条件完全一致）。
    """
    parameters = config.get('parameters', [])
    if not parameters:
        raise ValueError('配置中未定义任何实验参数 (parameters)')

    target = config.get('target', 'yield')
    batch_size = int(config.get('batch_size', 5))
    acq = config.get('acquisition_function', 'EI')
    init_method = config.get('init_method', 'rand')
    iterations = int(config.get('iterations', 10))
    seed = int(config.get('seed', 42))
    demo_mode = bool(config.get('demo_mode', True))

    rng = np.random.default_rng(seed)

    # ---- 构建实验域（全组合笛卡尔积）----
    from itertools import product
    names = [p['name'] for p in parameters]
    value_lists = [p['values'] for p in parameters]
    rows = [dict(zip(names, combo)) for combo in product(*value_lists)]
    domain = pd.DataFrame(rows)
    n_domain = len(domain)

    # ---- 数值特征空间（ohe 类别 + 数值），与 EDBO 引擎编码口径一致 ----
    domain_numeric, _ex, feature_columns, reverse_spec = build_feature_space(
        domain, parameters, target
    )
    Phi_all = domain_numeric[feature_columns].to_numpy(dtype=float)  # (n_domain, d)
    d = Phi_all.shape[1]

    # ---- 先验数据 ----
    prior_data = config.get('prior_results', [])
    prior_df = build_prior_results(prior_data, parameters, target, domain)
    has_prior = len(prior_df) > 0

    # ==================================================================
    # 正式模式（推荐）：不做合成评估，基于真实先验数据推荐下一批实验
    # ------------------------------------------------------------------
    if not demo_mode:
        return _mnl_recommend(
            config, parameters, names, domain, n_domain, Phi_all, d,
            feature_columns, reverse_spec, target, batch_size, acq,
            init_method, iterations, seed, prior_df, has_prior,
        )

    # ---- 合成目标（仅演示模式）----
    objective_fn, _ = build_synthetic_objective(parameters)
    # 全域合成目标（演示用；真实场景由先验/实验数据覆盖）
    # 用 np.array 显式创建独立可写数组，避免 to_numpy 在某些 pandas/numpy
    # 组合下返回只读缓冲导致先验回写失败。
    y_all = np.array(domain.apply(objective_fn, axis=1), dtype=float)

    # ---- 先验数据：用真实 y 覆盖对应组合 ----
    if has_prior:
        # 按参数列匹配，把先验真实目标写回 y_all
        merge_keys = names
        dom_merge = domain.copy()
        dom_merge['__idx'] = np.arange(n_domain)
        merged = prior_df[merge_keys + [target]].merge(
            dom_merge, on=merge_keys, how='inner'
        )
        for _, r in merged.iterrows():
            y_all[int(r['__idx'])] = float(r[target])

    # ---- 初始评估集 E ----
    if has_prior:
        # external 模式：先验点即已评估档案，跳过随机初始化
        prior_idx = []
        dom_merge = domain.copy()
        dom_merge['__idx'] = np.arange(n_domain)
        merged = prior_df[merge_keys].merge(dom_merge, on=merge_keys, how='inner')
        prior_idx = sorted(set(int(i) for i in merged['__idx']))
        evaluated = list(prior_idx)
        effective_init = 'external'
        print('[mnl] Using {} prior results as initial archive (external mode).'.format(len(evaluated)))
    else:
        # 初始随机设计：均匀抽取 n0 = batch_size 个互异组合
        n0 = min(batch_size, n_domain)
        evaluated = list(rng.choice(n_domain, size=n0, replace=False))
        effective_init = init_method

    evaluated = list(dict.fromkeys(evaluated))  # 去重保序
    eval_order = list(evaluated)                # 评估顺序（用于 batch 编号）

    def best_so_far():
        if not evaluated:
            return -np.inf, None
        ys = y_all[evaluated]
        k = int(np.argmax(ys))
        return float(ys[k]), evaluated[k]

    # ---- 收敛曲线累计最优 ----
    conv = []
    cum_best = -np.inf
    # 先把初始批次记入收敛曲线
    for i, idx in enumerate(eval_order):
        cum_best = max(cum_best, float(y_all[idx]))
        mean_y = float(np.mean(y_all[eval_order[: i + 1]]))
        conv.append({
            'step': len(conv) + 1,
            'best_yield': round(cum_best, 3),
            'mean_yield': round(mean_y, 3),
        })

    # 采集函数选择
    acq_upper = acq.upper()
    use_ucb = 'UCB' in acq_upper
    use_ts = 'TS' in acq_upper
    KAPPA = 2.0

    last_beta = None
    last_cov = None

    # ---- 主循环：每轮挑选 batch_size 个未评估候选 ----
    for t in range(iterations):
        uneval = [i for i in range(n_domain) if i not in set(evaluated)]
        if len(uneval) == 0:
            break
        if len(uneval) <= batch_size:
            # 剩余不足以组成一批，全选后结束
            selected = uneval
        else:
            selected = None  # 下面决定

        if selected is None:
            # 构造成对偏好（从已评估档案随机采样，封顶避免 O(N²)）
            n_eval = len(evaluated)
            n_pairs_max = min(400, n_eval * (n_eval - 1) // 2)
            pairs = []
            if n_pairs_max > 0:
                cand_i = np.array(evaluated)
                for _ in range(n_pairs_max):
                    a, b = rng.choice(cand_i, size=2, replace=False)
                    ya, yb = y_all[a], y_all[b]
                    if ya == yb:
                        continue
                    w, l = (a, b) if ya > yb else (b, a)
                    pairs.append((w, l))
            fit_ok = False
            if len(pairs) >= max(2, d):
                Phi_diff = Phi_all[[p[0] for p in pairs]] - Phi_all[[p[1] for p in pairs]]
                try:
                    beta, cov = fit_mnl_beta(Phi_diff)
                    last_beta, last_cov = beta, cov
                    fit_ok = True
                except np.linalg.LinAlgError:
                    fit_ok = False

            if not fit_ok:
                # 稳健性兜底：均匀随机选（论文 2.3）
                print('[mnl] surrogate fit unstable at iter {}, falling back to random selection.'.format(t))
                selected = list(rng.choice(uneval, size=batch_size, replace=False))
            else:
                # 预测效用 / 不确定度 / 选择概率（全域）
                mu_all = predict_utility(Phi_all, beta)
                sig_all = predict_unc(Phi_all, cov)
                pi_all = softmax(mu_all)

                mu_best = float(np.max(mu_all[evaluated]))

                if use_ts and last_cov is not None:
                    # Thompson 采样：从后验抽样一条效用面
                    try:
                        z = rng.standard_normal(d)
                        L = np.linalg.cholesky(last_cov + 1e-8 * np.eye(d))
                        beta_s = beta + L @ z
                        mu_sample = predict_utility(Phi_all, beta_s)
                        acq_all = mu_sample
                    except np.linalg.LinAlgError:
                        acq_all = mu_all + KAPPA * sig_all * pi_all
                elif use_ucb:
                    acq_all = mu_all + KAPPA * sig_all * pi_all
                else:
                    # EI_MNL：max(µ − µ⁺, 0) · π
                    improvement = np.clip(mu_all - mu_best, 0.0, None)
                    acq_all = improvement * pi_all

                # 只在未评估候选上取 top-k
                acq_uneval = acq_all[uneval]
                order = np.argsort(-acq_uneval)[:batch_size]
                selected = [uneval[k] for k in order]

        # 评估所选（目标已预计算），并入档案
        for idx in selected:
            if idx in set(evaluated):
                continue
            evaluated.append(idx)
            eval_order.append(idx)
            cum_best = max(cum_best, float(y_all[idx]))
            conv.append({
                'step': len(conv) + 1,
                'best_yield': round(cum_best, 3),
                'mean_yield': round(float(np.mean(y_all[eval_order])), 3),
            })

    # ---- 结果整理 ----
    results = domain.loc[evaluated].copy()
    results[target] = y_all[evaluated]
    results = results.reset_index(drop=True)
    results = restore_original_params(results, reverse_spec, names)

    best_idx = int(np.argmax(y_all[evaluated]))
    best_row = results.loc[best_idx]
    best = {name: (best_row[name] if name in best_row else None) for name in names}
    best[target] = round(float(best_row[target]), 3)

    experiments = []
    for i, (_, r) in enumerate(results.iterrows()):
        item = {name: r[name] for name in names}
        item[target] = round(float(r[target]), 3)
        item['batch'] = i // batch_size + 1
        experiments.append(item)

    # ---- 预测下一批（效用校准到产率尺度）----
    predicted_next = []
    if last_beta is not None and len(evaluated) >= 2:
        try:
            mu_eval = predict_utility(Phi_all[evaluated], last_beta)
            y_eval = y_all[evaluated]
            # 线性校准 y ≈ a·µ + b
            a, b = np.polyfit(mu_eval, y_eval, 1)
            uneval = [i for i in range(n_domain) if i not in set(evaluated)]
            if uneval:
                mu_u = predict_utility(Phi_all[uneval], last_beta)
                sig_u = predict_unc(Phi_all[uneval], last_cov)
                pi_u = softmax(np.concatenate([mu_eval, mu_u]))[len(evaluated):]
                mu_best = float(np.max(mu_eval))
                if use_ucb:
                    score = mu_u + KAPPA * sig_u * pi_u
                else:
                    score = np.clip(mu_u - mu_best, 0.0, None) * pi_u
                order = np.argsort(-score)[:batch_size]
                for k in order:
                    i = uneval[k]
                    item = {name: domain.loc[i, name] for name in names}
                    item['predicted_yield'] = round(float(a * mu_u[k] + b), 3)
                    # 方差映射到产率尺度：Var(y) = a²·Var(µ)
                    item['variance'] = round(float((a ** 2) * (sig_u[k] ** 2)), 3)
                    predicted_next.append(item)
        except Exception:
            predicted_next = []

    output = {
        'status': 'success',
        'mode': 'simulation',
        'engine': 'mnl',
        'target': target,
        'domain_size': int(n_domain),
        'parameter_names': names,
        'batch_size': batch_size,
        'acquisition_function': acq,
        'init_method': effective_init,
        'iterations': iterations,
        'prior_results_count': int(len(prior_df)),
        'total_evaluations': int(len(evaluated)),
        'best': best,
        'convergence': conv,
        'experiments': experiments,
        'predicted_next': predicted_next,
        'finished_at': datetime.now().isoformat(),
    }

    return output


def run(config_path, output_path):
    """CLI 入口：从文件读取配置，调用 run_config，写出结果 JSON。"""
    with open(config_path, 'r', encoding='utf-8-sig') as f:
        config = json.load(f)
    output = run_config(config)
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2, cls=NumpyEncoder)
    return output


def main():
    parser = argparse.ArgumentParser(description='MNL-BO Web bridge runner')
    parser.add_argument('--config', required=True, help='输入配置 JSON 路径')
    parser.add_argument('--output', required=True, help='输出结果 JSON 路径')
    args = parser.parse_args()

    try:
        run(args.config, args.output)
    except Exception as e:
        import traceback
        err = {
            'status': 'error',
            'message': str(e),
            'traceback': traceback.format_exc(),
        }
        try:
            with open(args.output, 'w', encoding='utf-8') as f:
                json.dump(err, f, ensure_ascii=False, indent=2)
        except Exception:
            pass
        sys.exit(1)


if __name__ == '__main__':
    main()
