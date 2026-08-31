"""MNL-BO 桥接环境自检脚本。

MNL-BO 为纯 numpy 实现，不依赖 edbo / torch / rdkit。本脚本仅验证
运行环境下 numpy 可用（pandas 由 edbo_runner 间接需要）。输出固定标记
'mnl-ok'，便于 PHP 侧用字符串包含判断。
"""
import sys

try:
    import numpy  # noqa: F401
    import pandas  # noqa: F401
    print("mnl-ok")
except Exception as exc:  # pragma: no cover
    print("mnl-fail: {}".format(exc))
    sys.exit(1)
