#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""真实 rdkit/Mordred 路径验证（离线：把取值当作其自身 SMILES）。

验证 edbo_chem.mordred 真实计算的描述符能正确：
  - 经 _resolve_to_descriptors 清洗/降维，得到可用的描述符列与 value_vectors
  - 经 restore_original_params 的最近邻还原回原始类别（即使加微小噪声）
需要：edbo_chem 可被导入（即 rdkit + mordred 已安装）。
"""
import sys
import os
import math

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import edbo_chem
# 离线：测试值直接作为 SMILES（避免 NIH CACTUS 网络请求）
edbo_chem.name_to_smiles = lambda v: str(v)

import edbo_runner as er
import pandas as pd


def main():
    col = pd.Series(['CCO', 'CCC', 'CCN'], name='Solvent')  # 乙醇/丙烷/乙胺
    info = er._resolve_to_descriptors(
        col, 'Solvent',
        edbo_chem.name_to_smiles, edbo_chem.mordred,
        edbo_chem.drop_single_value_columns, edbo_chem.drop_string_columns,
        edbo_chem.uncorrelated_features,
    )
    assert info is not None, "真实 Mordred 路径应返回描述符信息"
    desc_cols, value_vectors = info

    assert len(desc_cols) > 0, "应至少得到 1 个描述符列"
    assert all(c.startswith('Solvent_') for c in desc_cols), "描述符列应带前缀"
    for v, vec in value_vectors.items():
        assert set(vec.keys()) == set(desc_cols), "向量键与描述符列不一致: {}".format(v)
        assert all(math.isfinite(x) for x in vec.values()), "存在非有限描述符值: {}".format(v)

    print("[ok] desc_cols 数量 = {}, 示例 = {}".format(len(desc_cols), desc_cols[:4]))

    # 还原：对 'CCC' 向量加微小噪声，最近邻应还原回 'CCC'
    target = value_vectors['CCC']
    noise = {c: target[c] + 1e-6 * (i + 1) for i, c in enumerate(desc_cols)}
    df = pd.DataFrame([noise])
    reverse_spec = {
        'Solvent': {
            'type': 'resolve',
            'values': list(value_vectors.keys()),
            'cols': desc_cols,
            'vectors': value_vectors,
        }
    }
    out = er.restore_original_params(df, reverse_spec, ['Solvent'])
    assert out['Solvent'].tolist()[0] == 'CCC', "最近邻还原错误: {}".format(out['Solvent'].tolist())
    print("[ok] 最近邻还原正确: 加噪 'CCC' -> {}".format(out['Solvent'].tolist()[0]))

    print("\nREAL_RESOLVE_TEST_PASSED")


if __name__ == '__main__':
    main()
