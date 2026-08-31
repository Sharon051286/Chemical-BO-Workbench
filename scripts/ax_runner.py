#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Ax Web 桥接器 — Python 端
==========================

与 edbo_runner.py 协议兼容的 Ax 引擎实现。
复用 edbo_chem.py 的分子描述符编码能力，使用 Ax + BoTorch 做贝叶斯优化。

关键差异（vs edbo_runner.py）：
  - 使用 Sobol 准随机序列初始化（比 EDBO 的 rand 更均匀）
  - 使用 BoTorch 的 qEI Monte Carlo 采集函数（batch 场景推荐更准）
  - 先验数据通过 Ax 的 attach_trial 机制导入
  - 支持分子描述符编码（通过 edbo_chem.py）
  - 支持**多目标优化 (MOO)**：当 config 含 >1 个 objectives 时，自动切换到
    Ax 的 qNEHVI（超体积采集）多目标流程，输出 Pareto 前沿与超体积收敛曲线。
"""

import sys
import os
import json
import re
import keyword
import warnings
import argparse
import traceback
from datetime import datetime
from itertools import product

warnings.filterwarnings('ignore')

import numpy as np
import pandas as pd

# 将本脚本所在目录加入 sys.path，便于在 run() 内按需（try/except）导入 edbo_chem
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def _normalized_position(encoding, vals, value):
    """把任意参数取值映射到 [0,1] 归一化位置，使合成目标函数对连续/离散统一适用：
      - 连续数值(numeric)：按 [min,max] 线性归一化
      - 离散/类别/ohe/resolve：按取值在列表中的索引归一化
    这样数值参数转为连续区间后，目标函数仍能给出随取值平滑变化的信号。"""
    is_cont = (encoding == 'numeric') and all(
        isinstance(v, (int, float)) and not isinstance(v, bool) for v in vals
    )
    if is_cont:
        nums = [float(v) for v in vals]
        lo, hi = min(nums), max(nums)
        if hi <= lo:
            return 0.5
        return max(0.0, min(1.0, (float(value) - lo) / (hi - lo)))
    n = len(vals)
    if n <= 1:
        return 0.5
    idx = vals.index(value) if value in vals else 0
    return idx / (n - 1)


def build_synthetic_objective(parameters):
    """与 edbo_runner.py 相同的合成目标函数（单目标），支持连续数值变量。
    连续参数按 [min,max] 归一化，离散参数按索引归一化；峰值统一落在 0.6 处。"""
    names = [p['name'] for p in parameters]

    def objective(row):
        score = 1.0
        for p in parameters:
            name = p['name']
            pos = _normalized_position(p.get('encoding', 'numeric'), p['values'], row[name])
            norm = (pos - 0.6) / 0.25
            score *= np.exp(-(norm ** 2))
        score = score * 100.0 + np.random.normal(0, 1.5)
        return float(max(0.0, min(100.0, score)))

    return objective, names


def build_synthetic_objectives(parameters, objective_specs):
    """多目标合成函数：每个目标在不同参数位置取峰，刻意制造真实权衡关系，
    这样才能在演示中涌现出非平凡的 Pareto 前沿（而非所有目标同一点最优）。
    支持连续数值变量：连续参数按 [min,max] 归一化，离散参数按索引归一化。

    objective_specs: list of {'name': str, 'minimize': bool}
    返回 (obj_fn, obj_names)，obj_fn(row) -> {name: value}
    """
    n_params = len(parameters)
    k = len(objective_specs)

    # 为每个目标预计算其"峰值所在参数"与"最优归一化位置"（错开位置 -> 真实权衡）
    peaks = {}  # name -> (param_name, opt_frac, spread_norm)
    for i, spec in enumerate(objective_specs):
        p = parameters[i % n_params]
        # 在 [0.25, 0.75] 之间错开，目标越多分布越散
        frac = 0.25 + 0.5 * (i / max(1, k - 1)) if k > 1 else 0.6
        peaks[spec['name']] = (p['name'], frac, 0.22)

    def obj_fn(row):
        vals = {}
        for spec in objective_specs:
            pname, opt_frac, spread_norm = peaks[spec['name']]
            p = next(pp for pp in parameters if pp['name'] == pname)
            pos = _normalized_position(p.get('encoding', 'numeric'), p['values'], row[pname])
            norm = (pos - opt_frac) / spread_norm
            score = np.exp(-(norm ** 2)) * 100.0 + np.random.normal(0, 1.5)
            vals[spec['name']] = float(max(0.0, min(100.0, score)))
        return vals

    return obj_fn, [s['name'] for s in objective_specs]


def desc_param_name(name, d):
    """resolve 参数展开后的第 d 个描述符维度在 Ax 中的参数名。"""
    return '{}__desc{}'.format(name, d)


def _resolve_descriptor_matrix(name, values):
    """惰性调用 edbo_chem.resolve_descriptor_matrix；任何异常都返回 None（由调用方回退 One-Hot）。"""
    try:
        from edbo_chem import resolve_descriptor_matrix
        return resolve_descriptor_matrix(values, name=name)
    except Exception as e:
        print('[ax] resolve 描述符编码异常: {} -> {}'.format(name, e))
        return None


def _resolve_prior_index(spec, val):
    """先验值未精确命中候选集时（如别名 / 不同写法），重新编码该值并取描述符最近邻。
    失败则回退到首个候选。"""
    try:
        from edbo_chem import resolve_descriptor_matrix
        dm = resolve_descriptor_matrix([val], name='prior')
        if dm is None or dm['dim'] != spec['dim']:
            return 0
        vec = dm['matrix'][0]
        mat = spec['matrix']
        dists = np.abs(mat - vec).sum(axis=1)
        return int(np.argmin(dists))
    except Exception:
        return 0


def build_ax_space(parameters, chem_available):
    """根据 encoding 构建 Ax 参数定义与还原规则。

      - numeric : 连续数值变量，用 RangeParameter（float, bounds=[min,max]），
                  让 BO 在区间内连续探索，而非局限离散候选点
      - ohe     : 类别参数展开为 k 个 0/1 选择参数，消除假顺序危害
      - resolve : 分子类别变量（SMILES / 化合物名）通过 edbo_chem 编码为连续描述符向量，
                  每个分子展开成 D 个 [0,1] 的 RangeParameter（与 EDBOplus 思路一致），
                  使高斯过程能感知分子结构相似性；edbo_chem 不可用或编码失败时回退 One-Hot
    """
    ax_param_defs = []
    reverse_spec = {}
    for p in parameters:
        name = p['name']
        vals = p['values']
        enc = p.get('encoding', 'numeric')
        is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)

        if enc == 'numeric' and (not is_cat):
            # 连续数值变量：用 RangeParameter 让 BO 在 [min, max] 内连续探索，
            # 而非局限在离散候选点上——连续才是数值变量的本意
            nums = [float(v) for v in vals]
            lo, hi = min(nums), max(nums)
            if hi <= lo:
                hi = lo + 1.0
            ax_param_defs.append({
                'name': name,
                'type': 'range',
                'bounds': [lo, hi],
                'value_type': 'float',
            })
            reverse_spec[name] = {'type': 'continuous', 'bounds': [lo, hi]}
            continue

        if enc == 'resolve':
            if chem_available:
                dm = _resolve_descriptor_matrix(name, vals)
                if dm is not None and dm['dim'] > 0:
                    D = dm['dim']
                    for d in range(D):
                        ax_param_defs.append({
                            'name': desc_param_name(name, d),
                            'type': 'range',
                            'bounds': [0.0, 1.0],
                            'value_type': 'float',
                        })
                    reverse_spec[name] = {
                        'type': 'resolve',
                        'values': dm['values'],
                        'matrix': dm['matrix'],
                        'dim': D,
                    }
                    print('[ax] resolve 参数 "{}" 编码为 {} 维分子描述符'.format(name, D))
                    continue
                else:
                    print('[ax] resolve 描述符计算失败，回退 One-Hot: {}'.format(name))
            else:
                print('[ax] edbo_chem 不可用，resolve 回退 One-Hot: {}'.format(name))
            # 回退到 One-Hot
            enc = 'ohe'

        if is_cat and enc == 'ohe':
            for uv in sorted(set(vals), key=lambda x: str(x)):
                ax_param_defs.append({'name': '{}=={}'.format(name, uv), 'type': 'choice', 'values': [0, 1]})
            reverse_spec[name] = {'type': 'ohe', 'values': sorted(set(vals), key=lambda x: str(x))}
        else:
            ax_param_defs.append({'name': name, 'type': 'choice', 'values': [str(v) for v in vals]})
            reverse_spec[name] = {'type': 'direct'}
    return ax_param_defs, reverse_spec


def restore_ax_row(params, reverse_spec, parameters):
    """从 Ax 的 arm.parameters（可能含 One-Hot 展开列）还原原始参数取值字典。"""
    row = {}
    for p in parameters:
        name = p['name']
        spec = reverse_spec.get(name)
        if spec is None:
            continue
        if spec['type'] == 'direct':
            raw = params.get(name)
            matched = None
            for ov in p['values']:
                if str(ov) == str(raw) or ov == raw:
                    matched = ov
                    break
            row[name] = matched if matched is not None else raw
        elif spec['type'] == 'continuous':
            # 连续数值：Ax 返回 [lo, hi] 内的浮点值，保留 2 位小数（贴合实验精度，避免无意义长小数）
            try:
                row[name] = round(float(params.get(name)), 2)
            except (TypeError, ValueError):
                row[name] = params.get(name)
        elif spec['type'] == 'ohe':
            best = None
            for uv in spec['values']:
                if params.get('{}=={}'.format(name, uv), 0) == 1:
                    best = uv
                    break
            row[name] = best if best is not None else spec['values'][0]
        elif spec['type'] == 'resolve':
            # 把 D 个描述符维度的取值拼成向量，用 cityblock 最近邻还原回原始分子标识
            # （与 EDBOplus 把归一化采样点映射回最近组合的语义一致）
            vec = np.array([
                float(params.get(desc_param_name(name, d), 0.0))
                for d in range(spec['dim'])
            ])
            mat = spec['matrix']
            dists = np.abs(mat - vec).sum(axis=1)
            idx = int(np.argmin(dists))
            row[name] = spec['values'][idx]
    return row


def _params_signature(params):
    items = []
    for k in sorted(params.keys()):
        v = params[k]
        if isinstance(v, (int, float)) and not isinstance(v, bool):
            items.append((k, round(float(v), 8)))
        else:
            items.append((k, v))
    return tuple(items)


def _dedupe_prior_df(prior_df, names):
    """同一组实验条件只保留最后一次实测（避免 Ax 重复 arm）。"""
    if prior_df is None or len(prior_df) == 0:
        return prior_df
    cols = [n for n in names if n in prior_df.columns]
    if not cols:
        return prior_df.reset_index(drop=True)
    return prior_df.drop_duplicates(subset=cols, keep='last').reset_index(drop=True)


def _attach_completed_trial(ax_client, params, arm_name, raw_data, seen_keys):
    """挂载先验 trial；参数签名已存在时跳过，避免 Arm already exists。"""
    key = _params_signature(params)
    if key in seen_keys:
        return None
    try:
        _, trial_index = ax_client.attach_trial(parameters=params, arm_name=arm_name)
    except ValueError as e:
        if 'already exists' in str(e):
            return None
        raise
    ax_client.complete_trial(trial_index=trial_index, raw_data=raw_data)
    seen_keys.add(key)
    return trial_index


def _is_numeric_param(p):
    enc = p.get('encoding', 'numeric')
    vals = p.get('values') or []
    is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)
    return enc == 'numeric' and (not is_cat) and len(vals) >= 2


def _combo_close(a, b, parameters):
    """判断两组实验条件是否为同一组合（类别精确匹配，连续量按区间 1% 容差）。"""
    for p in parameters:
        name = p['name']
        if name not in a or name not in b:
            return False
        va, vb = a[name], b[name]
        if _is_numeric_param(p):
            try:
                fa, fb = float(va), float(vb)
            except (TypeError, ValueError):
                return False
            lo, hi = float(min(p['values'])), float(max(p['values']))
            span = abs(hi - lo) if hi != lo else 1.0
            if abs(fa - fb) > max(0.05, 0.01 * span):
                return False
        elif str(va).strip() != str(vb).strip():
            return False
    return True


def _row_used(row, used_rows, parameters):
    return any(_combo_close(row, u, parameters) for u in used_rows)


def _param_only(row, names):
    return {n: row[n] for n in names if n in row}


def _collect_used_rows(config, prior_df, names):
    used = []
    extra = config.get('used_combinations') or []
    if isinstance(extra, list):
        for r in extra:
            if isinstance(r, dict):
                used.append(_param_only(r, names))
    if prior_df is not None and len(prior_df) > 0:
        for _, r in prior_df.iterrows():
            used.append({n: r[n] for n in names})
    return used


def _abandon_trial(ax_client, trial_index):
    try:
        if hasattr(ax_client, 'abandon_trial'):
            ax_client.abandon_trial(trial_index=trial_index)
            return
    except Exception:
        pass
    try:
        trial = ax_client.experiment.trials.get(trial_index)
        if trial is not None and hasattr(trial, 'mark_abandoned'):
            trial.mark_abandoned()
    except Exception:
        pass


def _user_row_from_grid(grid_row, reverse_spec, parameters, names):
    restored = restore_ax_row(encode_prior_row(grid_row, reverse_spec, parameters), reverse_spec, parameters)
    return {name: restored[name] for name in names}


def _fill_unused_from_grid(needed, names, parameters, reverse_spec, ax_parameters, used_rows, seed=42):
    if needed <= 0:
        return []
    grid = _build_grid(parameters, reverse_spec, names, ax_parameters)
    order = list(range(grid['G']))
    np.random.RandomState(seed + 7).shuffle(order)
    extra = []
    for gi in order:
        item = _user_row_from_grid(grid['grid_rows'][gi], reverse_spec, parameters, names)
        if _row_used(item, used_rows, parameters):
            continue
        extra.append(item)
        used_rows.append(item)
        if len(extra) >= needed:
            break
    return extra


def encode_prior_row(prior_row, reverse_spec, parameters):
    """把一条先验原始记录（含类别取值）编码为 Ax 参数空间（One-Hot 展开）。

    注意区分 'direct'（ChoiceParameter，需字符串）与 'continuous'（RangeParameter，需 float）：
    数字形态取值（如 [70,90,80]）选 ohe 时，build_ax_space 会走 direct 兜底创建
    ChoiceParameter(['70','90','80'])，Ax 会把数值字符串归一化为 ['70.0','90.0','80.0']
    并要求 STRING 类型；这里必须用同样的字符串形式喂回去，否则 attach_trial 会报
    "80.0 is not a valid value for parameter ChoiceParameter"。
    """
    params = {}
    for p in parameters:
        name = p['name']
        spec = reverse_spec.get(name)
        if spec is None:
            continue
        val = prior_row.get(name)
        if spec['type'] == 'continuous':
            try:
                params[name] = float(val)
            except (ValueError, TypeError):
                params[name] = str(val)
        elif spec['type'] == 'direct':
            # ChoiceParameter：在 p['values'] 里匹配原值，按 Ax 归一化形式转字符串
            matched = None
            for ov in p['values']:
                if str(ov) == str(val) or ov == val:
                    matched = ov
                    break
            if matched is None:
                params[name] = str(val)
            elif isinstance(matched, (int, float)) and not isinstance(matched, bool):
                # 数字形态取值：Ax 归一化为带 ".0" 的字符串（'70' → '70.0'）
                params[name] = str(float(matched))
            else:
                params[name] = str(matched)
        elif spec['type'] == 'ohe':
            for uv in spec['values']:
                params['{}=={}'.format(name, uv)] = 1 if str(uv) == str(val) else 0
        elif spec['type'] == 'resolve':
            # 按原始取值索引取出该分子的 D 维描述符向量，写入 '{name}__desc{d}'
            try:
                idx = spec['values'].index(val)
            except ValueError:
                idx = _resolve_prior_index(spec, val)
            vec = spec['matrix'][idx]
            for d in range(spec['dim']):
                params[desc_param_name(name, d)] = float(vec[d])
    return params


# ===================================================================
#  多目标工具：Pareto 前沿 + 超体积（纯函数，不依赖 ax，便于单测）
# ===================================================================

def _dominates(a_vals, b_vals, objectives):
    """a 是否支配 b。objectives: list of {'name','minimize'}。
    最大化目标：a 越大越好；最小化目标：a 越小越好。
    支配 = 所有目标都不差，且至少一个严格更好。
    """
    better = False
    for o in objectives:
        av = a_vals[o['name']]
        bv = b_vals[o['name']]
        if o.get('minimize'):
            if av > bv:
                return False  # a 更差
            if av < bv:
                better = True
        else:
            if av < bv:
                return False
            if av > bv:
                better = True
    return better


def compute_pareto_front(rows, objectives):
    """从实验记录行（dict）中筛选非支配（Pareto 最优）集合。
    rows: list of dict（含各目标列）；返回非支配行的列表（保持原顺序）。
    """
    pareto = []
    for i, r in enumerate(rows):
        dominated = False
        for j, r2 in enumerate(rows):
            if i == j:
                continue
            if _dominates(r2, r, objectives):
                dominated = True
                break
        if not dominated:
            pareto.append(r)
    return pareto


def _to_max_space(vals, objectives):
    """把目标值转换到"统一最大化"空间（最小化目标取负）。"""
    return [(-vals[o['name']] if o.get('minimize') else vals[o['name']]) for o in objectives]


def _hv_2d(points_max, ref):
    """2 维超体积（最大化空间），ref 为最差参考点（左下角）。"""
    # 先取非支配点（最大化空间：越大越好）
    nd = []
    for i, p in enumerate(points_max):
        dom = False
        for j, q in enumerate(points_max):
            if i == j:
                continue
            if all(q[k] >= p[k] for k in range(2)) and any(q[k] > p[k] for k in range(2)):
                dom = True
                break
        if not dom:
            nd.append(p)
    nd.sort(key=lambda p: p[0])
    hv = 0.0
    prev_x = ref[0]
    for x, y in nd:
        if x <= ref[0] or y <= ref[1]:
            continue
        hv += (x - prev_x) * (y - ref[1])
        prev_x = x
    return max(0.0, hv)


def compute_hypervolume(rows, objectives, ref):
    """计算当前点集在目标空间下的超体积（hypervolume）。
    ref: dict name -> 参考点阈值（即 Ax 的 objective threshold）。
    2 维用精确算法；>2 维尝试用 BoTorch 的 Hypervolume，失败则回退为 Pareto 点数。
    """
    points_max = [_to_max_space(r, objectives) for r in rows]
    ref_max = [(-ref[o['name']] if o.get('minimize') else ref[o['name']]) for o in objectives]

    if len(objectives) == 2:
        return _hv_2d(points_max, ref_max)

    try:
        import torch
        from botorch.utils.multi_objective.hypervolume import Hypervolume
        ref_tensor = torch.tensor(ref_max, dtype=torch.double)
        pts_tensor = torch.tensor(points_max, dtype=torch.double)
        hv = Hypervolume(ref_point=ref_tensor)
        return float(hv.compute(pts_tensor))
    except Exception:
        # 回退：返回 Pareto 前沿点数（非负单调指标，仅用于演示趋势）
        return float(len(compute_pareto_front(rows, objectives)))


def _safe_metric_name(name):
    """Ax 把 objective 阈值转成 'name >= bound' 形式的约束字符串，再交给 sympy
    解析；Python 关键字（如 yield）或含特殊字符的名字会被 sympy 拒掉，报
    "Expected an inequality"。这里把目标名规整为 sympy 安全的合法标识符，
    输出时再映射回原名。仅用于喂给 Ax 的指标名，用户侧名称保持不变。"""
    base = re.sub(r'\W+', '_', str(name)).strip('_')
    if not base:
        base = 'obj'
    if not base[0].isalpha():
        base = 'm_' + base
    if keyword.iskeyword(base):
        base = 'obj_' + base
    return base


def _default_ref(objectives):
    """为合成目标（0-100 尺度）给出默认超体积参考点阈值。
    最大化目标：阈值取 0（最低有价值值）；最小化目标：阈值取 100（最高有价值值）。
    """
    ref = {}
    for o in objectives:
        ref[o['name']] = 0.0 if not o.get('minimize') else 100.0
    return ref


def _ax_predict(ax_client, params, metric):
    """对给定参数点做模型预测（已拟合 GP 的前提下），失败返回 None。
    兼容 AxClient.predict 的多种返回形态（dict 列表 / (means, covs) 元组）。"""
    try:
        out = ax_client.predict(parameters=[params])
    except Exception:
        return None
    try:
        if isinstance(out, tuple):
            means, _ = out
            m = means[0].get(metric) if means else None
        else:
            item = out[0]
            if isinstance(item, dict) and 'mean' in item:
                m = item['mean'].get(metric)
            elif isinstance(item, dict):
                m = item.get(metric)
            else:
                m = None
        return float(m) if m is not None else None
    except Exception:
        return None


MAX_MOO_CANDIDATES = 8000
GP_POSTERIOR_BATCH = 256


def _gp_mean_std(gp, X, batch_size=GP_POSTERIOR_BATCH):
    """分批预测 GP 均值/标准差，避免 ExactGP 一次性构造 N×N 测试协方差（会 OOM）。"""
    import torch
    import gpytorch

    means, stds = [], []
    gp.eval()
    n = int(X.shape[0])
    with torch.no_grad(), gpytorch.settings.fast_pred_var():
        for i in range(0, n, batch_size):
            post = gp.posterior(X[i:i + batch_size])
            means.append(post.mean.detach().cpu().reshape(-1).numpy())
            var = post.variance.detach().cpu().reshape(-1).numpy()
            stds.append(np.sqrt(np.clip(var, 0.0, None)))
    return np.concatenate(means), np.concatenate(stds)


def _build_grid(parameters, reverse_spec, names, ax_parameters):
    """构建离散/连续候选网格，及其归一化特征矩阵（供 EHVI 贪心改进使用）。
    连续参数默认在 [lo,hi] 内取最多 12 个均匀点；类别/ohe/resolve 取原始离散取值。
    笛卡尔积超过 MAX_MOO_CANDIDATES 时自动降低连续分辨率并抽样，避免 GP 后验 OOM。
    返回 dict: grid_rows, grid_feats_s, fmin, frange, G, row_to_feat
    """
    import torch

    axes_meta = []
    n_num = 0
    cat_prod = 1
    for p in parameters:
        enc = p.get('encoding', 'numeric')
        vals = p['values']
        is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)
        if enc == 'numeric' and (not is_cat):
            nums = [float(v) for v in vals]
            lo, hi = min(nums), max(nums)
            if hi <= lo:
                hi = lo + 1.0
            axes_meta.append(('num', lo, hi))
            n_num += 1
        else:
            cat_vals = [
                float(v) if (isinstance(v, (int, float)) and not isinstance(v, bool)) else v
                for v in vals
            ]
            axes_meta.append(('cat', cat_vals))
            cat_prod *= max(1, len(cat_vals))

    n_lin = 12
    if n_num > 0:
        while n_lin > 2 and (n_lin ** n_num) * cat_prod > MAX_MOO_CANDIDATES:
            n_lin -= 1

    grid_axes = []
    for kind, *rest in axes_meta:
        if kind == 'num':
            lo, hi = rest
            grid_axes.append(np.linspace(lo, hi, n_lin))
        else:
            grid_axes.append(np.array(rest[0], dtype=object))

    raw_size = 1
    for a in grid_axes:
        raw_size *= len(a)

    if raw_size <= MAX_MOO_CANDIDATES:
        grid_rows = [dict(zip(names, c)) for c in product(*grid_axes)]
    else:
        rng = np.random.RandomState(42)
        seen = set()
        grid_rows = []
        tries = 0
        limit = MAX_MOO_CANDIDATES * 40
        while len(grid_rows) < MAX_MOO_CANDIDATES and tries < limit:
            tries += 1
            combo = []
            key_parts = []
            for a in grid_axes:
                v = a[int(rng.randint(0, len(a)))]
                combo.append(v)
                if isinstance(v, (int, float, np.integer, np.floating)):
                    key_parts.append(round(float(v), 8))
                else:
                    key_parts.append(str(v))
            key = tuple(key_parts)
            if key in seen:
                continue
            seen.add(key)
            grid_rows.append(dict(zip(names, combo)))
        print('[ax] 多目标候选网格过大（约 {} 点），已抽样 {} 点以免内存耗尽。'.format(
            raw_size, len(grid_rows)))

    if raw_size > MAX_MOO_CANDIDATES or n_lin < 12:
        print('[ax] 多目标网格：连续分辨率 {}，候选 {} 点（原始笛卡尔积约 {}）。'.format(
            n_lin, len(grid_rows), raw_size))

    G = len(grid_rows)

    def row_to_feat(row):
        ap = encode_prior_row(row, reverse_spec, parameters)
        return [float(ap[pdef['name']]) for pdef in ax_parameters]

    grid_feats = np.array([row_to_feat(r) for r in grid_rows], dtype=float)
    fmin = grid_feats.min(axis=0)
    fmax = grid_feats.max(axis=0)
    frange = np.where(fmax - fmin == 0.0, 1.0, fmax - fmin)
    grid_feats_s = torch.tensor((grid_feats - fmin) / frange, dtype=torch.double)

    return {
        'grid_rows': grid_rows,
        'grid_feats_s': grid_feats_s,
        'fmin': fmin,
        'frange': frange,
        'G': G,
        'row_to_feat': row_to_feat,
    }


def run(config_path, output_path):
    """主流程：读取配置 → 构建域 → Ax 优化 → 写出结果。"""
    from ax.service.ax_client import AxClient

    # ---- 读取配置 ----
    with open(config_path, 'r', encoding='utf-8-sig') as f:
        config = json.load(f)

    parameters = config.get('parameters', [])
    if not parameters:
        raise ValueError('配置中未定义任何实验参数 (parameters)')

    # 统一数值参数类型：Ax 的 ChoiceParameter 要求所有 values 严格同类型
    # PHP json_encode 会把 2 输出成 int、2.5 输出成 float，混用会导致 Ax 推断失败
    for p in parameters:
        vals = p.get('values', [])
        if vals and all(isinstance(v, (int, float)) and not isinstance(v, bool) for v in vals):
            p['values'] = [float(v) for v in vals]

    objectives_config = config.get('objectives')
    # 多目标判定：objectives 存在且元素 > 1
    is_moo = isinstance(objectives_config, list) and len(objectives_config) > 1

    # 演示模式：用合成目标函数跑模拟（含收敛曲线、最优解等演示产物）；
    # 正式模式：不做合成评估，基于真实先验数据给出下一批实验推荐，用于真实迭代。
    demo_mode = bool(config.get('demo_mode', True))

    if is_moo:
        return _run_moo(config, parameters, objectives_config, output_path, demo_mode)
    return _run_single(config, parameters, output_path, demo_mode)


def _run_single(config, parameters, output_path, demo_mode=True):
    """单目标路径。
    demo_mode=True  : 演示模式，用合成目标函数模拟整轮优化（含收敛曲线、最优解）。
    demo_mode=False : 正式模式，不做合成评估；基于真实先验数据推荐下一批实验，供真实迭代。
    """
    from ax.service.ax_client import AxClient
    from ax.service.utils.instantiation import ObjectiveProperties

    target = config.get('target', 'yield')
    batch_size = int(config.get('batch_size', 5))
    acq = config.get('acquisition_function', 'EI')
    init_method = config.get('init_method', 'rand')
    iterations = int(config.get('iterations', 10))
    seed = int(config.get('seed', 42))

    names = [p['name'] for p in parameters]

    # 化学编码模块（edbo_chem）依赖 rdkit；不可用时 resolve 自动降级为 ohe
    try:
        from edbo_chem import encode_component  # noqa: F401
        CHEM_AVAILABLE = True
    except Exception:
        CHEM_AVAILABLE = False

    # ---- 离散组合规模 ----
    # 连续数值参数（numeric 且非类别）不构成有限网格，不计入；
    # 仅类别/ohe/resolve 参与离散组合数统计，供 domain_size 展示（不再构造笛卡尔积 DataFrame）
    domain_size = 1
    for p in parameters:
        enc = p.get('encoding', 'numeric')
        vals = p['values']
        is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)
        if enc in ('ohe', 'resolve') or is_cat:
            domain_size *= len(vals)

    # ---- 合成目标 ----
    objective_fn, _ = build_synthetic_objective(parameters)

    # ---- 处理先验数据 ----
    prior_data = config.get('prior_results', [])
    has_prior = bool(prior_data) and len(prior_data) > 0

    if has_prior:
        effective_init = 'external'
        prior_df = pd.DataFrame(prior_data)
        for name in names:
            if name not in prior_df.columns:
                raise ValueError("先验数据中缺少参数列 '{}'".format(name))
        if target not in prior_df.columns:
            raise ValueError("先验数据中缺少目标列 '{}'".format(target))
        prior_df = prior_df[names + [target]]
        prior_df[target] = pd.to_numeric(prior_df[target], errors='coerce')
        prior_df = prior_df.dropna(subset=[target])
        prior_df = _dedupe_prior_df(prior_df, names)
        print('Using {} prior results (external mode).'.format(len(prior_df)))
    else:
        effective_init = init_method
        prior_df = pd.DataFrame()

    # 目标值清洗后重新判定：空/非数值目标被丢弃后应回退为无先验
    has_prior = len(prior_df) > 0
    if not has_prior:
        effective_init = init_method

    # ---- 构建 Ax 参数空间（支持 numeric / ohe / resolve 编码）----
    # 类别参数默认 One-Hot 展开为 0/1 选择参数，Ax 不对 choice 假设顺序
    ax_parameters, reverse_spec = build_ax_space(parameters, CHEM_AVAILABLE)

    # ---- AxClient 初始化 ----
    # 不传 generation_strategy，让 Ax 自动选择 Sobol → BoTorch 策略
    ax_client = AxClient(random_seed=seed)

    ax_client.create_experiment(
        name='edbo_web_ax_optimization',
        parameters=ax_parameters,
        objectives={target: ObjectiveProperties(minimize=False)},
        is_test=True,
    )

    # ---- 导入先验数据（attach_trial）----
    if has_prior:
        seen_keys = set()
        for idx, row in prior_df.reset_index(drop=True).iterrows():
            params = encode_prior_row(row.to_dict(), reverse_spec, parameters)
            _attach_completed_trial(
                ax_client,
                params,
                'prior_{}'.format(idx),
                float(row[target]),
                seen_keys,
            )

    # ==================================================================
    # 正式模式（推荐）：不做合成评估，输出下一批实验推荐
    # ------------------------------------------------------------------
    # 真实实验迭代流程：
    #   - 无先验：输出空间填充初始设计（Sobol 准随机），供用户先跑一批真实实验；
    #   - 有先验：用 GP 在真实观测上拟合，按采集函数推荐 batch_size 个信息量最大的点；
    #   每点附带模型预测（predicted_<target>），帮助用户判断推荐是否合理。
    # ==================================================================
    if not demo_mode:
        used_rows = _collect_used_rows(config, prior_df, names)
        recommended = []
        attempts = 0
        max_attempts = max(60, batch_size * 40)
        while len(recommended) < batch_size and attempts < max_attempts:
            attempts += 1
            try:
                params, trial_index = ax_client.get_next_trial()
            except Exception:
                break
            row = restore_ax_row(params, reverse_spec, parameters)
            item = {name: row[name] for name in names}
            if _row_used(item, used_rows, parameters):
                _abandon_trial(ax_client, trial_index)
                continue
            if has_prior:
                pred = _ax_predict(ax_client, params, target)
                if pred is not None:
                    item['predicted_' + target] = round(float(pred), 3)
            recommended.append(item)
            used_rows.append(_param_only(item, names))

        if len(recommended) < batch_size:
            filled = _fill_unused_from_grid(
                batch_size - len(recommended),
                names, parameters, reverse_spec, ax_parameters, used_rows, seed,
            )
            recommended.extend(filled)

        if has_prior:
            experiments = []
            for _, r in prior_df.iterrows():
                item = {name: r[name] for name in names}
                item[target] = round(float(r[target]), 3)
                item['batch'] = 0
                experiments.append(item)
            best_idx = prior_df[target].idxmax()
            best_row = prior_df.loc[best_idx]
            best = {name: best_row[name] for name in names}
            best[target] = round(float(best_row[target]), 3)
        else:
            experiments = []
            best = None

        output = {
            'status': 'success',
            'mode': 'recommend',
            'engine': 'ax',
            'target': target,
            'domain_size': int(domain_size),
            'parameter_names': names,
            'batch_size': batch_size,
            'acquisition_function': acq,
            'init_method': effective_init,
            'iterations': iterations,
            'prior_results_count': int(len(prior_df)),
            'total_evaluations': int(len(experiments)),
            'best': best,
            'convergence': [],
            'experiments': experiments,
            'recommended_experiments': recommended,
            'predicted_next': recommended,
            'finished_at': datetime.now().isoformat(),
        }
        _write_output(output, output_path)
        return output

    # ---- Sobol 初始化 ----
    sobol_count = 0
    sobol_trials = batch_size if not has_prior else max(0, batch_size - len(prior_df))
    while sobol_count < sobol_trials:
        params, trial_index = ax_client.get_next_trial()
        row = restore_ax_row(params, reverse_spec, parameters)
        result = objective_fn(row)
        ax_client.complete_trial(trial_index=trial_index, raw_data=result)
        sobol_count += 1

    # ---- BoTorch GP 优化迭代 ----
    remaining_iterations = iterations
    for i in range(remaining_iterations):
        batch = []
        for _ in range(batch_size):
            try:
                params, trial_index = ax_client.get_next_trial()
                batch.append((trial_index, params))
            except Exception:
                break
        if not batch:
            break
        for trial_index, params in batch:
            row = restore_ax_row(params, reverse_spec, parameters)
            result = objective_fn(row)
            ax_client.complete_trial(trial_index=trial_index, raw_data=result)

    # ---- 收集结果 ----
    experiment = ax_client.experiment
    trials = experiment.trials

    all_data = []
    for trial_index in sorted(trials.keys()):
        trial = trials[trial_index]
        if trial.status.name != 'COMPLETED':
            continue
        arm = trial.arms_by_name.get(list(trial.arms_by_name.keys())[0])
        row = restore_ax_row(arm.parameters, reverse_spec, parameters)
        obj = trial.objective_mean
        row[target] = float(obj) if obj is not None else 0.0
        if trial_index < len(prior_df):
            row['batch'] = 0
        else:
            row['batch'] = (trial_index - len(prior_df)) // batch_size + 1
        all_data.append(row)

    results = pd.DataFrame(all_data).reset_index(drop=True)

    conv = []
    cum_max = results[target].cummax()
    mean_yield = results[target].expanding().mean()
    for i in range(len(results)):
        conv.append({
            'step': i + 1,
            'best_yield': round(float(cum_max.iloc[i]), 3),
            'mean_yield': round(float(mean_yield.iloc[i]), 3),
        })

    best_idx = results[target].idxmax()
    best_row = results.loc[best_idx]
    best = {name: best_row[name] for name in names}
    best[target] = round(float(best_row[target]), 3)

    experiments = []
    for _, r in results.iterrows():
        item = {name: r[name] for name in names}
        item[target] = round(float(r[target]), 3)
        item['batch'] = int(r['batch'])
        experiments.append(item)

    try:
        best_params, best_prediction = ax_client.get_best_parameters()
        best_params_restored = restore_ax_row(best_params, reverse_spec, parameters)
        predicted_next = [{
            **{name: best_params_restored[name] for name in names},
            'predicted_yield': round(float(best_prediction[0]['mean']), 3),
            'variance': round(float(best_prediction[0]['covariance']), 3),
        }]
    except Exception:
        predicted_next = []

    output = {
        'status': 'success',
        'mode': 'simulation',
        'engine': 'ax',
        'target': target,
        'domain_size': int(domain_size),
        'parameter_names': names,
        'batch_size': batch_size,
        'acquisition_function': acq,
        'init_method': effective_init,
        'iterations': iterations,
        'prior_results_count': int(len(prior_df)),
        'total_evaluations': int(len(results)),
        'best': best,
        'convergence': conv,
        'experiments': experiments,
        'predicted_next': predicted_next,
        'finished_at': datetime.now().isoformat(),
    }
    _write_output(output, output_path)
    return output


def _run_moo(config, parameters, objectives_config, output_path, demo_mode=True):
    """多目标路径：Ax qNEHVI + Pareto 前沿 + 超体积收敛。
    demo_mode=False 时不做合成评估，基于真实先验数据推荐下一批权衡实验。
    """
    from ax.service.ax_client import AxClient
    from ax.service.utils.instantiation import ObjectiveProperties

    target = config.get('target', objectives_config[0]['name'])
    batch_size = int(config.get('batch_size', 5))
    init_method = config.get('init_method', 'rand')
    iterations = int(config.get('iterations', 10))
    seed = int(config.get('seed', 42))
    obj_names = [o['name'] for o in objectives_config]
    # Ax 指标名需为 sympy 安全标识符（"yield" 等关键字会导致约束解析失败）
    name_map = {o['name']: _safe_metric_name(o['name']) for o in objectives_config}

    names = [p['name'] for p in parameters]

    try:
        from edbo_chem import encode_component  # noqa: F401
        CHEM_AVAILABLE = True
    except Exception:
        CHEM_AVAILABLE = False

    # ---- 离散组合规模（连续数值参数不计入有限网格）----
    domain_size = 1
    for p in parameters:
        enc = p.get('encoding', 'numeric')
        vals = p['values']
        is_cat = any(not isinstance(v, (int, float)) or isinstance(v, bool) for v in vals)
        if enc in ('ohe', 'resolve') or is_cat:
            domain_size *= len(vals)

    # ---- 多目标合成函数（各目标错峰，制造真实权衡）----
    obj_fn, _ = build_synthetic_objectives(parameters, objectives_config)

    # ---- 先验数据 ----
    prior_data = config.get('prior_results', [])
    has_prior = bool(prior_data) and len(prior_data) > 0
    if has_prior:
        effective_init = 'external'
        prior_df = pd.DataFrame(prior_data)
        for name in names:
            if name not in prior_df.columns:
                raise ValueError("先验数据中缺少参数列 '{}'".format(name))
        for oname in obj_names:
            if oname not in prior_df.columns:
                raise ValueError("先验数据中缺少目标列 '{}'".format(oname))
        prior_df = prior_df[names + obj_names]
        for oname in obj_names:
            prior_df[oname] = pd.to_numeric(prior_df[oname], errors='coerce')
        prior_df = prior_df.dropna(subset=obj_names)
        prior_df = _dedupe_prior_df(prior_df, names)
        print('Using {} prior results (external mode, MOO).'.format(len(prior_df)))
    else:
        effective_init = init_method
        prior_df = pd.DataFrame()

    # 目标值清洗后重新判定：空/非数值目标被丢弃后应回退为无先验
    has_prior = len(prior_df) > 0
    if not has_prior:
        effective_init = init_method

    # ---- Ax 参数空间 ----
    ax_parameters, reverse_spec = build_ax_space(parameters, CHEM_AVAILABLE)

    # ---- 超体积参考点（objective threshold）----
    # 优先用 config 显式阈值，否则用默认（0-100 尺度）
    ref_cfg = config.get('objective_thresholds') or {}
    ref = {}
    for o in objectives_config:
        if o['name'] in ref_cfg:
            ref[o['name']] = float(ref_cfg[o['name']])
        else:
            ref[o['name']] = 0.0 if not o.get('minimize') else 100.0

    # ---- 创建多目标实验：Ax 自动切换 qNEHVI ----
    ax_client = AxClient(random_seed=seed)
    ax_client.create_experiment(
        name='edbo_web_ax_moo',
        parameters=ax_parameters,
        objectives={
            name_map[o['name']]: ObjectiveProperties(minimize=bool(o.get('minimize', False)), threshold=ref[o['name']])
            for o in objectives_config
        },
        is_test=True,
    )

    # 按 trial_index 记录每行（还原参数 + 全部目标值），避免依赖 trial.objective_mean
    trial_records = {}

    # ---- 导入先验 ----
    if has_prior:
        seen_keys = set()
        for idx, row in prior_df.reset_index(drop=True).iterrows():
            params = encode_prior_row(row.to_dict(), reverse_spec, parameters)
            restored = restore_ax_row(params, reverse_spec, parameters)
            rec = dict(restored)
            for oname in obj_names:
                rec[oname] = float(row[oname])
            raw = {name_map[oname]: (float(row[oname]), 0.0) for oname in obj_names}
            trial_index = _attach_completed_trial(
                ax_client, params, 'prior_{}'.format(idx), raw, seen_keys,
            )
            if trial_index is None:
                continue
            rec['batch'] = int(row['batch']) if 'batch' in row and pd.notna(row['batch']) else 0
            rec['_gi'] = -1
            trial_records[trial_index] = rec

    # ==================================================================
    # 正式模式（推荐）：不做合成评估，输出下一批权衡实验推荐
    # ------------------------------------------------------------------
    #   - 无先验：推荐空间填充初始设计（从候选网格取 batch_size 个准随机点）；
    #   - 有先验：拟合 GP 后在网格上做 EHVI 贪心，选出下一批信息量最大的点；
    #   experiments 仅含真实观测（先验），Pareto 前沿由真实观测计算。
    # ==================================================================
    if not demo_mode:
        import torch
        from botorch.models import SingleTaskGP
        from botorch.fit import fit_gpytorch_mll
        import gpytorch
        from gpytorch.mlls import ExactMarginalLogLikelihood

        _grid = _build_grid(parameters, reverse_spec, names, ax_parameters)
        grid_rows = _grid['grid_rows']
        grid_feats_s = _grid['grid_feats_s']
        fmin = _grid['fmin']
        frange = _grid['frange']
        G = _grid['G']
        row_to_feat = _grid['row_to_feat']

        used_rows = _collect_used_rows(config, prior_df, names)
        free = []
        for gi in range(G):
            item = _user_row_from_grid(grid_rows[gi], reverse_spec, parameters, names)
            if not _row_used(item, used_rows, parameters):
                free.append(gi)

        if not has_prior:
            rng_pick = np.random.RandomState(seed)
            _pool = list(free)
            rng_pick.shuffle(_pool)
            pick = _pool[:min(batch_size, len(_pool))]
        else:
            # 在真实观测上拟合各目标 GP，做 EHVI 贪心选点（不合成评估）
            X = np.array([row_to_feat({k: rec[k] for k in names}) for rec in trial_records.values()], dtype=float)
            Xs = (X - fmin) / frange
            Xs_t = torch.tensor(Xs, dtype=torch.double)
            Ystats = []
            Ys_t = []
            for on in obj_names:
                y = np.array([rec[on] for rec in trial_records.values()], dtype=float)
                m = float(y.mean())
                s = float(y.std())
                if s <= 1e-9:
                    s = 1.0
                Ystats.append({'m': m, 's': s})
                Ys_t.append(torch.tensor((y - m) / s, dtype=torch.double).unsqueeze(1))
            models = []
            for yt in Ys_t:
                try:
                    gp = SingleTaskGP(Xs_t, yt)
                    mll = ExactMarginalLogLikelihood(gp.likelihood, gp)
                    fit_gpytorch_mll(mll)
                    models.append(gp)
                except Exception:
                    models.append(None)
            opt = {}
            for k, on in enumerate(obj_names):
                gp = models[k]
                if gp is None:
                    opt[on] = np.full(G, Ystats[k]['m'])
                    continue
                mean_s, std_s = _gp_mean_std(gp, grid_feats_s)
                mean_u = mean_s * Ystats[k]['s'] + Ystats[k]['m']
                std_u = std_s * Ystats[k]['s']
                beta = 0.5
                if objectives_config[k].get('minimize'):
                    opt[on] = mean_u - beta * std_u
                else:
                    opt[on] = mean_u + beta * std_u
            ref_dicts = [{on: rec[on] for on in obj_names} for rec in trial_records.values()]
            cur = compute_hypervolume(ref_dicts, objectives_config, ref)
            pool = list(free)
            pick = []
            for _ in range(min(batch_size, len(pool))):
                if not pool:
                    break
                best_inc = -1e18
                best_i = None
                for i in pool:
                    cdict = {on: float(opt[on][i]) for on in obj_names}
                    inc = compute_hypervolume(ref_dicts + [cdict], objectives_config, ref) - cur
                    if inc > best_inc:
                        best_inc = inc
                        best_i = i
                if best_i is None:
                    break
                pick.append(best_i)
                ref_dicts.append({on: float(opt[on][best_i]) for on in obj_names})
                pool.remove(best_i)
                cur = cur + best_inc

        # 组装推荐批次（再次过滤，防止网格还原后与已用组合撞车）
        recommended = []
        for gi in pick:
            item = _user_row_from_grid(grid_rows[gi], reverse_spec, parameters, names)
            if _row_used(item, used_rows, parameters):
                continue
            recommended.append(item)
            used_rows.append(item)
        if len(recommended) < batch_size:
            for gi in free:
                if len(recommended) >= batch_size:
                    break
                item = _user_row_from_grid(grid_rows[gi], reverse_spec, parameters, names)
                if _row_used(item, used_rows, parameters):
                    continue
                recommended.append(item)
                used_rows.append(item)

        # 真实观测（先验）作为已评估实验。正式首轮无先验时 records 为空，不得对空列做 min/max。
        experiments = []
        for rec in trial_records.values():
            item = {name: rec[name] for name in names}
            for oname in obj_names:
                item[oname] = round(float(rec[oname]), 3)
            item[target] = round(float(rec[target]), 3)
            item['batch'] = int(rec['batch'])
            experiments.append(item)

        recs = list(trial_records.values())
        pareto_front = []
        best_per_objective = {}
        best = None
        if recs:
            pareto_rows = compute_pareto_front(recs, objectives_config)
            for r in pareto_rows:
                item = {name: r[name] for name in names}
                for oname in obj_names:
                    item[oname] = round(float(r[oname]), 3)
                item['batch'] = int(r['batch'])
                pareto_front.append(item)

            for o in objectives_config:
                col = [rec[o['name']] for rec in recs]
                if not col:
                    continue
                best_per_objective[o['name']] = round(float(min(col) if o.get('minimize') else max(col)), 3)
            ideal = dict(best_per_objective)
            best_idx_compromise = 0
            best_dist = None
            for i, r in enumerate(recs):
                d = 0.0
                for o in objectives_config:
                    if o['name'] not in ideal:
                        continue
                    v = -r[o['name']] if o.get('minimize') else r[o['name']]
                    iv = -ideal[o['name']] if o.get('minimize') else ideal[o['name']]
                    d += (v - iv) ** 2
                d = d ** 0.5
                if best_dist is None or d < best_dist:
                    best_dist = d
                    best_idx_compromise = i
            compromise = {name: recs[best_idx_compromise][name] for name in names}
            for oname in obj_names:
                compromise[oname] = round(float(recs[best_idx_compromise][oname]), 3)
            best = dict(best_per_objective)
            best['compromise'] = compromise
            if obj_names[0] in best_per_objective:
                best[target] = best_per_objective[obj_names[0]]

        output = {
            'status': 'success',
            'mode': 'recommend',
            'engine': 'ax',
            'multi_objective': True,
            'target': target,
            'objectives': objectives_config,
            'objective_thresholds': ref,
            'domain_size': int(domain_size),
            'parameter_names': names,
            'batch_size': batch_size,
            'acquisition_function': 'qNEHVI (Ax 多目标自动)',
            'init_method': effective_init,
            'iterations': iterations,
            'prior_results_count': int(len(prior_df)),
            'total_evaluations': int(len(experiments)),
            'best': best,
            'best_per_objective': best_per_objective,
            'pareto_front': pareto_front,
            'hypervolume': [],
            'convergence': [],
            'experiments': experiments,
            'recommended_experiments': recommended,
            'predicted_next': recommended,
            'finished_at': datetime.now().isoformat(),
        }
        _write_output(output, output_path)
        return output

    # ==================================================================
    # 多目标优化核心
    # ------------------------------------------------------------------
    # 说明：Ax 默认的 qNEHVI 采集在「离散网格」上会卡死（Sobol→BoTorch 切换后
    # get_next_trial 长时间无响应），因此这里不再调用 Ax 的采集器，而是：
    #   1) 用 AxClient 仅作为「数据容器」记录真实评估；
    #   2) 用 botorch 为每个目标独立拟合 GP 代理模型；
    #   3) 在「有限离散网格」上直接评估采集函数，做贪心超体积改进（EHVI 风格）
    #      —— 不依赖连续空间上的采集器优化，故不会卡死、确定性强、契合离散网格 BO。
    # ==================================================================
    import torch
    from botorch.models import SingleTaskGP
    from botorch.fit import fit_gpytorch_mll
    import gpytorch
    from gpytorch.mlls import ExactMarginalLogLikelihood

    rng = np.random.RandomState(seed)

    # ---- 构建候选网格与特征向量（模拟与正式推荐共用）----
    _grid = _build_grid(parameters, reverse_spec, names, ax_parameters)
    grid_rows = _grid['grid_rows']
    grid_feats_s = _grid['grid_feats_s']
    fmin = _grid['fmin']
    frange = _grid['frange']
    G = _grid['G']
    row_to_feat = _grid['row_to_feat']

    def eval_record(row, batch):
        params = encode_prior_row(row, reverse_spec, parameters)
        restored = restore_ax_row(params, reverse_spec, parameters)
        objs = obj_fn(restored)
        return params, restored, objs

    def record_trial(row, batch, gi):
        params, restored, objs = eval_record(row, batch)
        _, trial_index = ax_client.attach_trial(parameters=params, arm_name='bo_{}'.format(gi))
        raw = {name_map[on]: (objs[on], 0.0) for on in obj_names}
        ax_client.complete_trial(trial_index=trial_index, raw_data=raw)
        rec = dict(restored)
        rec.update(objs)
        rec['batch'] = batch
        rec['_gi'] = gi
        trial_records[trial_index] = rec
        return gi

    # ---- 初始采样（随机/准随机，不调用 get_next_trial）----
    evaluated_gi = set()
    init_n = min(batch_size, G)
    init_pool = list(range(G))
    rng.shuffle(init_pool)
    for gi in init_pool[:init_n]:
        record_trial(grid_rows[gi], 1, gi)
        evaluated_gi.add(gi)

    # ---- BO 迭代：贪心超体积改进 ----
    for it in range(iterations):
        n_eval = len(trial_records)
        if n_eval >= G:
            break
        eval_list = list(trial_records.values())

        if n_eval < 2:
            # 数据不足以拟合 GP：随机补点
            remaining = [i for i in range(G) if i not in evaluated_gi]
            rng.shuffle(remaining)
            pick = remaining[:batch_size]
        else:
            # 训练集特征（用用户行还原，兼容先验点）
            X = np.array([row_to_feat({k: rec[k] for k in names}) for rec in eval_list], dtype=float)
            Xs = (X - fmin) / frange
            Xs_t = torch.tensor(Xs, dtype=torch.double)
            # 各目标独立标准化后拟合 GP
            Ystats = []
            Ys_t = []
            for on in obj_names:
                y = np.array([rec[on] for rec in eval_list], dtype=float)
                m = float(y.mean())
                s = float(y.std())
                if s <= 1e-9:
                    s = 1.0
                Ystats.append({'m': m, 's': s})
                Ys_t.append(torch.tensor((y - m) / s, dtype=torch.double).unsqueeze(1))
            models = []
            for yt in Ys_t:
                try:
                    gp = SingleTaskGP(Xs_t, yt)
                    mll = ExactMarginalLogLikelihood(gp.likelihood, gp)
                    fit_gpytorch_mll(mll)
                    models.append(gp)
                except Exception:
                    models.append(None)
            # 预测所有网格点的「乐观值」（max 目标 +k*std；min 目标 -k*std）
            opt = {}
            for k, on in enumerate(obj_names):
                gp = models[k]
                if gp is None:
                    opt[on] = np.full(G, Ystats[k]['m'])
                    continue
                mean_s, std_s = _gp_mean_std(gp, grid_feats_s)
                mean_u = mean_s * Ystats[k]['s'] + Ystats[k]['m']
                std_u = std_s * Ystats[k]['s']
                beta = 0.5
                if objectives_config[k].get('minimize'):
                    opt[on] = mean_u - beta * std_u
                else:
                    opt[on] = mean_u + beta * std_u
            # 贪心选择：以真实评估点为参考集，逐步加入使超体积增量最大的候选
            ref_dicts = [{on: rec[on] for on in obj_names} for rec in eval_list]
            cur = compute_hypervolume(ref_dicts, objectives_config, ref)
            pool = [i for i in range(G) if i not in evaluated_gi]
            pick = []
            for _ in range(batch_size):
                if not pool:
                    break
                best_inc = -1e18
                best_i = None
                for i in pool:
                    cdict = {on: float(opt[on][i]) for on in obj_names}
                    inc = compute_hypervolume(ref_dicts + [cdict], objectives_config, ref) - cur
                    if inc > best_inc:
                        best_inc = inc
                        best_i = i
                if best_i is None:
                    break
                pick.append(best_i)
                ref_dicts.append({on: float(opt[on][best_i]) for on in obj_names})
                pool.remove(best_i)
                cur = cur + best_inc

        # 评估选中的点
        batch_no = 2 + it
        for gi in pick:
            record_trial(grid_rows[gi], batch_no, gi)
            evaluated_gi.add(gi)

    # ---- 汇总全部评估 ----
    records = [trial_records[t] for t in sorted(trial_records.keys())]
    results = pd.DataFrame(records).reset_index(drop=True)

    # 每条实验含全部目标列；为兼容下游单目标消费者，保留 target 列 = 首目标
    results[target] = results[obj_names[0]]

    experiments = []
    for _, r in results.iterrows():
        item = {name: r[name] for name in names}
        for oname in obj_names:
            item[oname] = round(float(r[oname]), 3)
        item[target] = round(float(r[target]), 3)
        item['batch'] = int(r['batch'])
        experiments.append(item)

    # ---- Pareto 前沿 ----
    pareto_rows = compute_pareto_front(records, objectives_config)
    pareto_front = []
    for r in pareto_rows:
        item = {name: r[name] for name in names}
        for oname in obj_names:
            item[oname] = round(float(r[oname]), 3)
        item['batch'] = int(r['batch'])
        pareto_front.append(item)

    # ---- 超体积收敛（累计，按评估顺序）----
    hv_conv = []
    cum = []
    for i, r in enumerate(records):
        cum.append(r)
        hv = compute_hypervolume(cum, objectives_config, ref)
        hv_conv.append({'step': i + 1, 'hypervolume': round(hv, 4)})

    # ---- 每个目标各自最优 + 折中解（距理想点最近）----
    best_per_objective = {}
    for o in objectives_config:
        col = results[o['name']]
        if o.get('minimize'):
            idx = col.idxmin()
        else:
            idx = col.idxmax()
        best_per_objective[o['name']] = round(float(col.iloc[idx]), 3)

    # 折中：在统一最大化空间内，距理想点（各目标最大值）归一化欧氏距离最近者
    ideal = {}
    for o in objectives_config:
        col = results[o['name']]
        ideal[o['name']] = float(col.min() if o.get('minimize') else col.max())
    best_idx_compromise = 0
    best_dist = None
    for i, r in results.iterrows():
        d = 0.0
        for o in objectives_config:
            v = -r[o['name']] if o.get('minimize') else r[o['name']]
            iv = -ideal[o['name']] if o.get('minimize') else ideal[o['name']]
            d += (v - iv) ** 2
        d = d ** 0.5
        if best_dist is None or d < best_dist:
            best_dist = d
            best_idx_compromise = i
    comp = results.loc[best_idx_compromise]
    compromise = {name: comp[name] for name in names}
    for oname in obj_names:
        compromise[oname] = round(float(comp[oname]), 3)

    best = dict(best_per_objective)
    best['compromise'] = compromise
    best[target] = best_per_objective[obj_names[0]]

    # ---- 预测下一批：多目标下返回当前 Pareto 最优解集（即推荐权衡方案）----
    predicted_next = []
    for r in pareto_front:
        item = {name: r[name] for name in names}
        for oname in obj_names:
            item[oname] = r[oname]
        item['is_pareto'] = True
        predicted_next.append(item)

    output = {
        'status': 'success',
        'mode': 'simulation',
        'engine': 'ax',
        'multi_objective': True,
        'target': target,
        'objectives': objectives_config,
        'objective_thresholds': ref,
        'domain_size': int(domain_size),
        'parameter_names': names,
        'batch_size': batch_size,
        'acquisition_function': 'qNEHVI (Ax 多目标自动)',
        'init_method': effective_init,
        'iterations': iterations,
        'prior_results_count': int(len(prior_df)),
        'total_evaluations': int(len(results)),
        'best': best,
        'best_per_objective': best_per_objective,
        'pareto_front': pareto_front,
        'hypervolume': hv_conv,
        'convergence': hv_conv,
        'experiments': experiments,
        'predicted_next': predicted_next,
        'finished_at': datetime.now().isoformat(),
    }
    _write_output(output, output_path)
    return output


def _write_output(output, output_path):
    class NumpyEncoder(json.JSONEncoder):
        def default(self, obj):
            if isinstance(obj, np.integer):
                return int(obj)
            if isinstance(obj, np.floating):
                return float(obj)
            if isinstance(obj, np.ndarray):
                return obj.tolist()
            return super().default(obj)

    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2, cls=NumpyEncoder)


def main():
    parser = argparse.ArgumentParser(description='Ax Web bridge runner')
    parser.add_argument('--config', required=True, help='输入配置 JSON 路径')
    parser.add_argument('--output', required=True, help='输出结果 JSON 路径')
    args = parser.parse_args()

    try:
        run(args.config, args.output)
    except Exception as e:
        err = {
            'status': 'error',
            'engine': 'ax',
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
