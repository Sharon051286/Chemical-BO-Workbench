#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
EDBO Web 桥接器 — Python 端
============================

本脚本由 Laravel 后端通过 micromamba 环境调用，负责：
  1. 读取 JSON 配置（实验域、优化设置）
  2. 在 EDBO 环境中构建实验空间
  3. 运行贝叶斯优化（模拟模式，含合成目标函数）
  4. 将结果写入输出 JSON 文件

设计要点（资深开发视角）：
  - 结果写入文件而非 stdout，避免 EDBO 内部日志污染输出流
  - 全程 try/except，任何异常都写出结构化错误 JSON，方便 PHP 侧定位
  - 合成目标函数用于演示 EDBO 的优化能力；接入真实实验数据时只需替换此函数
"""

import sys
import os
import json
import warnings
import argparse
from datetime import datetime

# 抑制 EDBO 内部 edbo bot 等噪声输出，结果只通过文件返回
warnings.filterwarnings('ignore')

import numpy as np
import pandas as pd

# 让 EDBO 的 bot 静默：重定向 stdout 中 bot 的打印已由文件输出规避，
# 这里再保险地忽略运行时警告。
import logging
logging.basicConfig(level=logging.ERROR)


def build_synthetic_objective(parameters):
    """
    构造一个平滑的多峰合成目标函数，用于演示 EDBO 的寻优能力。

    设计：对每个参数，将离散取值归一化到 [-1, 1]，最优位置取中间偏后，
    目标 = 各参数高斯项的乘积（带轻微噪声）。这样 EDBO 可以在有限网格上
    找到明确的最优组合，直观展示收敛过程。

    注意：这是合成景观，并非真实化学产率。接入真实实验时，把本函数替换为
    从实验数据库读取产率即可（例如用 exindex + 人工录入）。
    """
    names = [p['name'] for p in parameters]
    # 每个参数的最优取值索引（故意不在边界，制造"内部最优"）
    opt_index = {}
    spread = {}
    for p in parameters:
        vals = p['values']
        n = len(vals)
        # 最优放在约 60% 位置，避免退化成"越大越好"
        opt_index[p['name']] = max(0, min(n - 1, int(n * 0.6)))
        # 高斯宽度：覆盖约 1/3 的取值数量
        spread[p['name']] = max(0.6, n / 3.0)

    def objective(row):
        score = 1.0
        for name in names:
            vals = parameters[[p['name'] for p in parameters].index(name)]['values']
            n = len(vals)
            idx = vals.index(row[name]) if row[name] in vals else 0
            norm = (idx - opt_index[name]) / spread[name]
            score *= np.exp(-(norm ** 2))
        # 轻微噪声模拟实验误差
        score = score * 100.0 + np.random.normal(0, 1.5)
        return float(max(0.0, min(100.0, score)))

    return objective, names


def build_prior_results(prior_data, parameters, target, domain):
    """
    将前端传入的先验实验数据转换为 EDBO 可消费的 DataFrame。

    先验数据格式：list of dict，每个 dict 包含所有参数列 + 目标列。
    例如：
        [
            {"温度(℃)": 80, "时间(h)": 1, "催化剂(mol%)": 1, "yield": 45.2},
            {"温度(℃)": 120, "时间(h)": 4, "催化剂(mol%)": 5, "yield": 78.6},
        ]

    转换逻辑：
      1. 转为 DataFrame
      2. 确保列名与 domain 一致（参数列 + 目标列）
      3. 对齐数据类型：数值列转 float，类别列保持原样
      4. 仅保留 domain 中存在的行（过滤掉不在实验域内的先验数据）

    返回 DataFrame 或空 DataFrame（无先验数据时）。
    """
    if not prior_data:
        return pd.DataFrame()

    names = [p['name'] for p in parameters]
    df = pd.DataFrame(prior_data)

    # 确保目标列存在
    if target not in df.columns:
        raise ValueError("先验数据中缺少目标列 '{}'".format(target))

    # 确保所有参数列都存在
    for name in names:
        if name not in df.columns:
            raise ValueError("先验数据中缺少参数列 '{}'".format(name))

    # 仅保留参数列 + 目标列，避免多余列干扰
    df = df[names + [target]]

    # 数值化：尝试将每列转为数值，失败（类别型）则保持原样。
    # 注意：新版 pandas (3.x) 已移除 to_numeric 的 errors='ignore'，
    # 故用 coerce + where 还原非数值单元格，等价于旧版 'ignore'。
    for name in names:
        coerced = pd.to_numeric(df[name], errors='coerce')
        df[name] = coerced.where(coerced.notna(), df[name])
    df[target] = pd.to_numeric(df[target], errors='coerce')
    df = df.dropna(subset=[target])  # 目标值无效的行丢弃

    # 过滤：只保留 domain 中存在的行（先验数据必须在实验域内）
    # 通过 merge 判断每行是否在 domain 中
    domain['_in_domain'] = True
    merged = df.merge(domain[names + ['_in_domain']], on=names, how='left')
    domain.drop(columns=['_in_domain'], inplace=True)
    df = merged[merged['_in_domain'] == True].drop(columns=['_in_domain'])

    df = df.reset_index(drop=True)
    return df


def _resolve_to_descriptors(col, name, name_to_smiles, mordred,
                            drop_single_value_columns, drop_string_columns,
                            uncorrelated_features):
    """把类别列按 resolve 编码展开为 Mordred 分子描述符。

    流程：每个取值 → NIH CACTUS 解析 SMILES → Mordred 全量描述符 →
    去字符串列 + 去零方差列 + 去高相关列（降维，避免 GP 维度爆炸）。
    返回 (desc_cols, value_vectors)：
      - desc_cols      : 实际作为特征的列名列表
      - value_vectors  : {原始取值: {描述符列: 数值}}，用于最近邻还原
    任一取值无法解析为 SMILES 或没有可用描述符时返回 None（调用方降级 ohe）。
    """
    unique_vals = sorted(col.unique(), key=lambda x: str(x))
    smiles_map = {}
    for uv in unique_vals:
        try:
            smi = name_to_smiles(str(uv))
        except Exception:
            smi = 'FAILED'
        if smi == 'FAILED' or not smi:
            return None
        smiles_map[uv] = smi

    try:
        desc = mordred(list(smiles_map.values()), name=name, dropna=True)
    except Exception:
        return None
    if desc is None or len(desc) == 0:
        return None

    # 清洗：去字符串列、去零方差列、去高相关列
    desc = drop_string_columns(desc)
    desc = drop_single_value_columns(desc)
    try:
        desc = uncorrelated_features(desc, threshold=0.95)
    except Exception:
        pass

    id_col = name + '_SMILES'
    if id_col in desc.columns:
        desc = desc.drop(columns=[id_col])

    desc_cols = [c for c in desc.columns if c != id_col]
    if len(desc_cols) == 0:
        return None

    # 按 unique_vals 顺序还原每个值的描述符向量（mordred 保留输入顺序）
    value_vectors = {}
    for i, uv in enumerate(unique_vals):
        row = desc.iloc[i]
        value_vectors[uv] = {c: float(row[c]) for c in desc_cols}

    return desc_cols, value_vectors


def build_feature_space(domain, parameters, target):
    """
    根据每个参数的 encoding 构建数值特征空间（EDBO 的 BO 只接受数值域）。

      - numeric : 数值参数，原值直接作为特征
      - ohe     : 类别参数展开为 k 个 0/1 哑变量，消除「假顺序」危害
                  （优于原先按字典序映射成 0,1,2 的 ordinal 编码）
      - resolve : 真实化学描述符（需 rdkit）；不可用时降级为 ohe

    返回 (domain_numeric, exindex_numeric, feature_columns, reverse_spec)：
      - feature_columns : 实际喂给 BO 的列名
      - reverse_spec    : 如何从特征空间还原原始参数取值（direct / ohe）
    """
    # 化学编码模块（edbo_chem）依赖 rdkit + mordred；不可用时 resolve 自动降级为 ohe
    try:
        from edbo_chem import (
            encode_component, name_to_smiles, mordred,
            drop_single_value_columns, drop_string_columns, uncorrelated_features,
        )
        CHEM_AVAILABLE = True
    except Exception:
        CHEM_AVAILABLE = False

    names = [p['name'] for p in parameters]
    domain_numeric = pd.DataFrame(index=domain.index)
    feature_columns = []
    reverse_spec = {}

    for name in names:
        enc = next((p.get('encoding', 'numeric') for p in parameters if p['name'] == name), 'numeric')
        col = domain[name]
        is_cat = col.dtype == object or col.map(lambda x: isinstance(x, str)).any()

        if enc == 'resolve':
            if CHEM_AVAILABLE and is_cat:
                # 阶段二：真实 Mordred 分子描述符（名称 → SMILES → 描述符）
                # 替代假顺序的 ordinal 编码，让模型真正“理解”化合物结构
                desc_info = _resolve_to_descriptors(
                    col, name,
                    name_to_smiles, mordred,
                    drop_single_value_columns, drop_string_columns,
                    uncorrelated_features,
                )
                if desc_info is None:
                    print('[edbo] resolve 编码无法解析（SMILES/描述符不可用），降级为 One-Hot: {}'.format(name))
                    enc = 'ohe'
                else:
                    desc_cols, value_vectors = desc_info
                    for dc in desc_cols:
                        domain_numeric[dc] = col.map(
                            lambda v, dc=dc: float(value_vectors[v].get(dc, 0.0))
                        )
                        feature_columns.append(dc)
                    reverse_spec[name] = {
                        'type': 'resolve',
                        'values': list(value_vectors.keys()),
                        'cols': desc_cols,
                        'vectors': value_vectors,
                    }
                    continue  # 该参数已展开为描述符列，跳过下方通用分支
            else:
                if not CHEM_AVAILABLE:
                    print('[edbo] resolve 编码需 rdkit，当前不可用，已降级为 One-Hot: {}'.format(name))
                enc = 'ohe'

        if (not is_cat) and enc in ('numeric', 'resolve'):
            # 数值参数：原值作为特征
            domain_numeric[name] = pd.to_numeric(col, errors='coerce')
            feature_columns.append(name)
            reverse_spec[name] = {'type': 'direct', 'col': name}
        elif is_cat and enc == 'ohe':
            # One-Hot：展开为 k 个 0/1 列，模型不再假设类别间有顺序/距离
            unique_vals = sorted(col.unique(), key=lambda x: str(x))
            for uv in unique_vals:
                colname = '{}=={}'.format(name, uv)
                domain_numeric[colname] = (col == uv).astype(int)
                feature_columns.append(colname)
            reverse_spec[name] = {'type': 'ohe', 'values': unique_vals}
        else:
            # 兜底：ordinal 整数编码（仅当数值参数被误标为类别时）
            unique_vals = sorted(col.unique(), key=lambda x: str(x))
            forward = {v: i for i, v in enumerate(unique_vals)}
            domain_numeric[name] = col.map(forward).astype(float)
            feature_columns.append(name)
            reverse_spec[name] = {'type': 'direct', 'col': name}

    # 目标值仍基于原始取值计算，再挂到数值索引上
    exindex_numeric = domain_numeric.copy()
    exindex_numeric[target] = domain.apply(
        lambda row: 0.0, axis=1
    )  # 占位，真正目标在调用处填充

    return domain_numeric, exindex_numeric, feature_columns, reverse_spec


def restore_original_params(df, reverse_spec, names):
    """把数值特征空间（含 One-Hot 列）还原为原始参数取值，便于结果展示。"""
    out = df.copy()
    for name in names:
        spec = reverse_spec.get(name)
        if spec is None:
            continue
        if spec['type'] == 'ohe':
            cols = ['{}=={}'.format(name, v) for v in spec['values']]
            present = [c for c in cols if c in out.columns]
            if not present:
                continue
            vals = out[present].values
            idx = vals.argmax(axis=1)
            out[name] = [spec['values'][i] for i in idx]
            out = out.drop(columns=present)
        elif spec['type'] == 'resolve':
            # 描述符是连续向量，GP 预测值会有漂移，用最近邻还原回原始类别
            cols = spec['cols']
            present = [c for c in cols if c in out.columns]
            if len(present) == 0:
                continue
            vectors = spec['vectors']
            values = spec['values']
            cand = np.array(
                [[vectors[v].get(c, 0.0) for c in present] for v in values],
                dtype=float,
            )
            data = out[present].values.astype(float)
            diff = data[:, None, :] - cand[None, :, :]
            dist = np.sqrt((diff ** 2).sum(axis=2))
            idx = dist.argmin(axis=1)
            out[name] = [values[i] for i in idx]
            out = out.drop(columns=present)
        # 'direct'：列本身已是原始取值，保留即可
    return out


def run(config_path, output_path):
    """主流程：读取配置 -> 构建 -> 优化 -> 写出结果。"""
    from edbo.bro import BO

    # ---- 读取配置 ----
    with open(config_path, 'r', encoding='utf-8-sig') as f:
        config = json.load(f)

    parameters = config.get('parameters', [])
    if not parameters:
        raise ValueError('配置中未定义任何实验参数 (parameters)')

    target = config.get('target', 'yield')
    batch_size = int(config.get('batch_size', 5))
    acq = config.get('acquisition_function', 'EI')
    init_method = config.get('init_method', 'rand')
    iterations = int(config.get('iterations', 10))
    seed = int(config.get('seed', 42))

    # ---- 构建实验域（全组合笛卡尔积）----
    from itertools import product

    names = [p['name'] for p in parameters]
    value_lists = [p['values'] for p in parameters]

    rows = []
    for combo in product(*value_lists):
        rows.append(dict(zip(names, combo)))
    domain = pd.DataFrame(rows)

    # ---- 构建数值特征空间（支持 numeric / ohe / resolve 编码）----
    # 类别参数默认 One-Hot，消除原先按字典序映射成 0,1,2 的假顺序危害
    domain_numeric, exindex_numeric, feature_columns, reverse_spec = build_feature_space(
        domain, parameters, target
    )
    objective_fn, _ = build_synthetic_objective(parameters)
    # 目标值基于原始取值计算，再挂到数值索引上
    exindex_numeric[target] = domain.apply(objective_fn, axis=1)

    # ---- 处理先验数据 ----
    prior_data = config.get('prior_results', [])
    prior_df = build_prior_results(prior_data, parameters, target, domain)
    has_prior = len(prior_df) > 0

    # 先验数据也按同一特征空间编码（ohe 展开 / 数值保留）
    if has_prior:
        for name in names:
            spec = reverse_spec.get(name)
            if spec is None:
                continue
            if spec['type'] == 'ohe':
                for uv in spec['values']:
                    colname = '{}=={}'.format(name, uv)
                    prior_df[colname] = (prior_df[name] == uv).astype(int)
                prior_df = prior_df.drop(columns=[name])
            elif spec['type'] == 'resolve':
                # 先验行按值查表展开成同一组描述符列
                cols = spec['cols']
                for c in cols:
                    prior_df[c] = prior_df[name].map(
                        lambda v, c=c: float(spec['vectors'].get(v, {}).get(c, 0.0))
                    )
                prior_df = prior_df.drop(columns=[name])
            # 'direct'：数值列原样保留
        prior_df = prior_df[
            [c for c in feature_columns if c in prior_df.columns] + [target]
        ]

    if has_prior:
        # 有先验数据时，强制使用 external 模式：跳过随机初始化，直接用已有数据
        # 让 GP 模型从真实观测起步，收敛更快、推荐质量更高
        effective_init = 'external'
        print('Using {} prior results for initialization (external mode).'.format(len(prior_df)))
    else:
        effective_init = init_method

    # ---- 初始化 BO（仅用数值特征列）----
    bo = BO(
        domain=domain_numeric[feature_columns],
        results=prior_df if has_prior else pd.DataFrame(),
        exindex=exindex_numeric[feature_columns + [target]],
        target=target,
        batch_size=batch_size,
        acquisition_function=acq,
        init_method=effective_init,
    )

    # ---- 运行模拟优化 ----
    bo.simulate(
        iterations=iterations,
        seed=seed,
        training_iters=int(config.get('training_iters', 100)),
    )

    # ---- 收集结果 ----
    results = bo.obj.results_input().copy()
    results = results.reset_index(drop=True)

    # 将数值特征空间（One-Hot 等）还原为原始参数取值
    results = restore_original_params(results, reverse_spec, names)

    # 收敛曲线：按批次累加最优
    conv = []
    running_best = -np.inf
    batch_ids = results.index // batch_size if len(results) >= batch_size else results.index
    # 用累计最大值构造收敛曲线
    cum_max = results[target].cummax()
    mean_yield = results[target].expanding().mean()
    for i in range(len(results)):
        running_best = max(running_best, results[target].iloc[i])
        conv.append({
            'step': i + 1,
            'best_yield': round(float(cum_max.iloc[i]), 3),
            'mean_yield': round(float(mean_yield.iloc[i]), 3),
        })

    # 最优实验
    best_idx = results[target].idxmax()
    best_row = results.loc[best_idx]
    best = {name: (best_row[name] if name in best_row else None) for name in names}
    best[target] = round(float(best_row[target]), 3)

    # 全部实验（含批次号）
    experiments = []
    for i, (_, r) in enumerate(results.iterrows()):
        item = {name: r[name] for name in names}
        item[target] = round(float(r[target]), 3)
        item['batch'] = i // batch_size + 1
        experiments.append(item)

    # 预测下一批（基于已训练模型对全域的预测，挑 top-k 未实验点）
    # 注意：GP_Model.predict/variance 必须在数值特征空间上调用（不能用含字符串的
    # 原始 domain），预测值为标准化空间，需用 scaler 反标准化回真实产率尺度。
    try:
        pred_df = domain_numeric[feature_columns].copy()
        preds = bo.model.predict(pred_df)            # numpy, 标准化空间
        vars_ = np.sqrt(bo.model.variance(pred_df))  # numpy
        preds = bo.obj.scaler.unstandardize(preds)   # 反标准化到真实产率

        pred_df['pred'] = preds
        pred_df['var'] = vars_

        # 还原原始参数取值，便于与已实验点比对、展示
        pred_df = restore_original_params(pred_df, reverse_spec, names)

        # 已评估过的点标记为 done，从中剔除，只推荐未实验点
        done = results[names].copy()
        done['__done'] = True
        merged = pred_df.merge(done, on=names, how='left')
        candidate = merged[merged['__done'].isna()].sort_values('pred', ascending=False).head(batch_size)

        predicted_next = []
        for _, r in candidate.iterrows():
            item = {name: r[name] for name in names}
            item['predicted_yield'] = round(float(r['pred']), 3)
            item['variance'] = round(float(r['var']), 3)
            predicted_next.append(item)
    except Exception:
        predicted_next = []

    output = {
        'status': 'success',
        'mode': 'simulation',
        'target': target,
        'domain_size': int(len(domain)),
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

    return output


def main():
    parser = argparse.ArgumentParser(description='EDBO Web bridge runner')
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
