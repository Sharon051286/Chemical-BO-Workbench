#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Ax 引擎健康检查脚本。"""

import sys

print("ax-checking...")

try:
    import ax
    print("ax-version:" + ax.__version__)
except Exception as e:
    print("ax-failed:" + str(e))
    sys.exit(1)

try:
    import botorch
    print("botorch-ok")
except Exception as e:
    print("botorch-failed:" + str(e))
    sys.exit(1)

try:
    from rdkit import Chem
    from mordred import Calculator, descriptors
    print("chem-ok")
except Exception as e:
    print("chem-failed:" + str(e))
    sys.exit(1)

print("ax-ok")
