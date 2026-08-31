#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
ax_runner 单元测试 + 集成测试
=============================
- 纯函数单测（不触发 Ax）：_dominates / compute_pareto_front /
  compute_hypervolume(2D 精确) / _default_ref
- 集成测试 run()：单目标（回归，确保行为不变）与多目标（MOO 新能力）

运行：/d/miniconda3/envs/edbo-ax/python.exe scripts/test_ax_runner.py
"""

import json
import os
import sys
import tempfile

# 让本脚本能 import 同目录的 ax_runner
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import ax_runner as R  # noqa: E402


# ----------------------------------------------------------------------------
# 1) 纯函数单测
# ----------------------------------------------------------------------------

def test_dominates_maximize():
    objs = [{'name': 'f1', 'minimize': False}, {'name': 'f2', 'minimize': False}]
    a = {'f1': 90, 'f2': 60}
    b = {'f1': 80, 'f2': 50}
    assert R._dominates(a, b, objs) is True, "a 在两个目标上都更好 -> 应支配"
    c = {'f1': 90, 'f2': 50}
    d = {'f1': 80, 'f2': 60}
    assert R._dominates(c, d, objs) is False, "一好一差 -> 不应支配"
    # 相等 -> 无严格更优 -> 不支配
    assert R._dominates(a, dict(a), objs) is False, "相等不应支配"


def test_dominates_minimize():
    objs = [{'name': 'cost', 'minimize': True}]
    a = {'cost': 10}
    b = {'cost': 20}
    assert R._dominates(a, b, objs) is True, "minimize: 10<20，a 更优 -> 应支配"
    assert R._dominates(b, a, objs) is False, "minimize: 20>10，b 更差 -> 不应支配"


def test_pareto_front():
    objs = [{'name': 'f1', 'minimize': False}, {'name': 'f2', 'minimize': False}]
    rows = [
        {'id': 'P1', 'f1': 90, 'f2': 20},
        {'id': 'P2', 'f1': 70, 'f2': 60},
        {'id': 'P3', 'f1': 40, 'f2': 90},
        {'id': 'P4', 'f1': 50, 'f2': 50},   # 被 P2 支配
        {'id': 'P5', 'f1': 60, 'f2': 55},   # 被 P2 支配
        {'id': 'P6', 'f1': 95, 'f2': 10},   # 极端点
        {'id': 'P7', 'f1': 30, 'f2': 95},   # 极端点
    ]
    front = R.compute_pareto_front(rows, objs)
    ids = sorted(r['id'] for r in front)
    assert ids == ['P1', 'P2', 'P3', 'P6', 'P7'], "Pareto 集合应为 5 个非支配点, 实际: %s" % ids
    assert len(front) == 5


def test_hypervolume_2d_exact():
    objs = [{'name': 'f1', 'minimize': False}, {'name': 'f2', 'minimize': False}]
    rows = [
        {'f1': 90, 'f2': 20},
        {'f1': 70, 'f2': 60},
        {'f1': 40, 'f2': 90},
        {'f1': 50, 'f2': 50},
        {'f1': 60, 'f2': 55},
        {'f1': 95, 'f2': 10},
        {'f1': 30, 'f2': 95},
    ]
    ref = R._default_ref(objs)  # f1->0, f2->0
    hv = R.compute_hypervolume(rows, objs, ref)
    # 手算：非支配点按 x 升序 (30,95),(40,90),(70,60),(90,20),(95,10)
    # HV = 30*95 + 10*90 + 30*60 + 20*20 + 5*10 = 2850+900+1800+400+50 = 6000
    assert abs(hv - 6000.0) < 1e-6, "2D 超体积手算应为 6000, 实际: %s" % hv


def test_hypervolume_2d_minimize_one():
    # 一个最小化目标：cost 越小越好；yield 越大越好
    objs = [{'name': 'yield', 'minimize': False}, {'name': 'cost', 'minimize': True}]
    rows = [
        {'yield': 80, 'cost': 30},
        {'yield': 60, 'cost': 20},
        {'yield': 40, 'cost': 10},
        {'yield': 50, 'cost': 25},  # 被 (60,20) 支配
    ]
    ref = R._default_ref(objs)  # yield->0, cost->100
    hv = R.compute_hypervolume(rows, objs, ref)
    # 不应崩溃，且为非负有限值
    assert hv >= 0 and hv == hv, "含最小化目标的超体积应有效: %s" % hv
    # 含最小化目标时，_to_max_space 对 cost 取负，参考点变 -100
    front = R.compute_pareto_front(rows, objs)
    assert len(front) == 3, "应剔除 (50,25)"


def test_default_ref():
    objs = [{'name': 'a', 'minimize': False}, {'name': 'b', 'minimize': True}]
    ref = R._default_ref(objs)
    assert ref == {'a': 0.0, 'b': 100.0}, ref


# ----------------------------------------------------------------------------
# 2) 集成测试 run()（触发 Ax）
# ----------------------------------------------------------------------------

def _write_config(cfg):
    fd, path = tempfile.mkstemp(suffix='.json', prefix='cfg_')
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        json.dump(cfg, f)
    return path


def test_run_single_objective_regression():
    cfg = {
        'parameters': [
            {'name': 'T', 'values': [10.0, 20.0, 30.0, 40.0, 50.0]},
            {'name': 'pH', 'values': [1.0, 2.0, 3.0, 4.0]},
        ],
        'target': 'yield',
        'batch_size': 2,
        'iterations': 3,
        'seed': 42,
        'init_method': 'rand',
    }
    cfg_path = _write_config(cfg)
    out_path = tempfile.mktemp(suffix='.json', prefix='out_single_')
    out = R.run(cfg_path, out_path)
    assert out['status'] == 'success', out.get('message')
    assert not out.get('multi_objective'), "单目标不应设置 multi_objective"
    assert out['target'] == 'yield'
    assert 'convergence' in out and out['convergence']
    assert 'experiments' in out and len(out['experiments']) >= 1
    # 单目标 experiments 仅含 target 列（无 objectives/pareto 键）
    assert 'pareto_front' not in out
    assert 'objectives' not in out
    print("  [single] evals=%d best=%s" % (out['total_evaluations'], out['best']))
    os.remove(cfg_path)
    os.remove(out_path)


def test_run_multi_objective():
    cfg = {
        'parameters': [
            {'name': 'T', 'values': [10.0, 20.0, 30.0, 40.0, 50.0]},
            {'name': 'pH', 'values': [1.0, 2.0, 3.0, 4.0]},
        ],
        'objectives': [
            {'name': 'yield', 'minimize': False},
            {'name': 'cost', 'minimize': True},
        ],
        'batch_size': 2,
        'iterations': 4,
        'seed': 7,
    }
    cfg_path = _write_config(cfg)
    out_path = tempfile.mktemp(suffix='.json', prefix='out_moo_')
    out = R.run(cfg_path, out_path)
    assert out['status'] == 'success', out.get('message')
    assert out['multi_objective'] is True
    assert len(out['objectives']) == 2
    assert 'yield' in out['objective_thresholds'] and 'cost' in out['objective_thresholds']
    assert len(out['pareto_front']) >= 1, "应产出非空 Pareto 前沿"
    assert len(out['hypervolume']) == out['total_evaluations'], "超体积曲线应逐点累计"
    # 超体积应单调非减
    hv = [h['hypervolume'] for h in out['hypervolume']]
    assert all(hv[i] <= hv[i + 1] + 1e-9 for i in range(len(hv) - 1)), "超体积应单调非减"
    assert 'compromise' in out['best'], "应给出折中解"
    assert set(out['best_per_objective'].keys()) == {'yield', 'cost'}
    # experiments 同时含两个目标列
    assert 'yield' in out['experiments'][0] and 'cost' in out['experiments'][0]
    print("  [moo] evals=%d pareto=%d hv_final=%.3f"
          % (out['total_evaluations'], len(out['pareto_front']), hv[-1]))
    os.remove(cfg_path)
    os.remove(out_path)


def main():
    pure = [
        test_dominates_maximize,
        test_dominates_minimize,
        test_pareto_front,
        test_hypervolume_2d_exact,
        test_hypervolume_2d_minimize_one,
        test_default_ref,
    ]
    integration = [
        test_run_single_objective_regression,
        test_run_multi_objective,
    ]

    failed = []
    print("== 纯函数单测 ==")
    for fn in pure:
        try:
            fn()
            print("  PASS  %s" % fn.__name__)
        except AssertionError as e:
            failed.append((fn.__name__, str(e)))
            print("  FAIL  %s : %s" % (fn.__name__, e))

    print("== 集成测试（触发 Ax）==")
    for fn in integration:
        try:
            fn()
            print("  PASS  %s" % fn.__name__)
        except Exception as e:  # noqa: BLE001
            failed.append((fn.__name__, repr(e)))
            print("  FAIL  %s : %s" % (fn.__name__, e))

    print("")
    if failed:
        print("结果: %d 个失败" % len(failed))
        for name, msg in failed:
            print("  - %s : %s" % (name, msg))
        sys.exit(1)
    print("结果: 全部通过 ✅")


if __name__ == '__main__':
    main()
