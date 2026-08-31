"""EDBO 桥接环境自检脚本。

由 App\Services\EdboService::healthCheck() 调用，验证 EDBO 在目标 conda 环境中
可被正常 import。输出固定标记，便于 PHP 侧用字符串包含判断。

设计取舍：不用 `python -c "..."` 内联，因为 Windows 下经 cmd /c 包裹后，
-c 参数的内层双引号会被 cmd 吞掉导致语法错误；独立脚本文件规避该问题。
"""
import sys

try:
    import edbo  # noqa: F401
    print("edbo-ok")
except Exception as exc:  # pragma: no cover - 仅用于自检诊断
    print("edbo-fail: {}".format(exc))
    sys.exit(1)
