# -*- coding: utf-8 -*-
"""
独立化学编码模块 — 从 EDBO feature_utils.py / chem_utils.py 抽取。

不依赖 EDBO 的 BO 流程、bot() 交互或 Data 容器，
可在外部环境（如 Ax）中独立使用。

依赖：rdkit, mordred, pandas, numpy, scikit-learn
"""

import pandas as pd
import numpy as np
from itertools import product
from urllib.request import urlopen

try:
    from rdkit import Chem
except ImportError:
    raise ImportError("rdkit is required. Install with: pip install rdkit")

# mordred 为可选依赖：缺失时模块仍可导入（其他编码路径不受影响），
# 仅在真正调用 Mordred 描述符时给出明确报错，由调用方回退 One-Hot。
try:
    from mordred import Calculator, descriptors
    MORDRED_AVAILABLE = True
except ImportError:
    Calculator = None
    descriptors = None
    MORDRED_AVAILABLE = False


# ──────────────────────────────────────────────
# 1. Mordred 分子描述符
# ──────────────────────────────────────────────

def mordred(smiles_list, name='', dropna=False):
    """计算 SMILES 列表的 Mordred 全量分子描述符。

    Parameters
    ----------
    smiles_list : list
        SMILES 字符串列表。
    name : str
        描述符列名前缀（如 'substrate' → 'substrate_SMR'）。
    dropna : bool
        是否丢弃含 NaN 的列。

    Returns
    -------
    pandas.DataFrame
        第一列为 SMILES 字符串，其余列为 Mordred 描述符数值。
    """
    if not MORDRED_AVAILABLE:
        raise ImportError("mordred is required for Mordred descriptors. Install with: pip install mordred")
    smiles_list = list(smiles_list)
    calc = Calculator(descriptors)

    output = []
    for entry in smiles_list:
        try:
            data_i = calc(Chem.MolFromSmiles(entry)).fill_missing()
        except Exception:
            data_i = np.full(len(calc.descriptors), np.NaN)
        output.append(list(data_i))

    descriptor_names = list(calc.descriptors)
    columns = [name + '_' + str(d) for d in descriptor_names]

    df = pd.DataFrame(data=output, columns=columns)
    df.insert(0, name + '_SMILES', smiles_list)

    if dropna:
        df = df.dropna(axis=1)

    return df


# ──────────────────────────────────────────────
# 2. One-Hot 编码
# ──────────────────────────────────────────────

def one_hot_encode(data_column, name=''):
    """对数据列做 one-hot 编码。

    Parameters
    ----------
    data_column : pandas.Series
        待编码的列。
    name : str
        列名前缀。

    Returns
    -------
    pandas.DataFrame
        第一列为原始值，其余列为 one-hot 编码。
    """
    possible_values = list(data_column.drop_duplicates())

    ohe = []
    for value in possible_values:
        row = [1 if v == value else 0 for v in possible_values]
        ohe.append(row)

    columns = [name + '=' + str(v) for v in possible_values]
    ohe_df = pd.DataFrame(data=ohe, columns=columns)
    ohe_df.insert(0, name, possible_values)

    return ohe_df


# ──────────────────────────────────────────────
# 3. 化合物名称 → SMILES
# ──────────────────────────────────────────────

def name_to_smiles(name):
    """通过 NIH CACTUS 数据库将化合物名称解析为 SMILES 字符串。

    Parameters
    ----------
    name : str
        化合物名称或别名。

    Returns
    -------
    str
        SMILES 字符串，解析失败返回 'FAILED'。
    """
    name = name.replace(' ', '%20')
    try:
        url = 'http://cactus.nci.nih.gov/chemical/structure/' + name + '/smiles'
        smiles = urlopen(url).read().decode('utf8')
        smiles = str(smiles)
        if '</div>' in smiles:
            return 'FAILED'
        return smiles
    except Exception:
        return 'FAILED'


# ──────────────────────────────────────────────
# 4. 统一编码分发器
# ──────────────────────────────────────────────

def encode_component(df_column, encoding, name=''):
    """根据编码类型对数据列进行编码。

    与 EDBO 原版功能一致，但去除了 bot() 交互逻辑——
    遇到错误时直接 fallback 到 one-hot 编码。

    Parameters
    ----------
    df_column : pandas.Series
        待编码的列。
    encoding : str
        编码方式：'ohe', 'mordred', 'smiles', 'numeric', 'resolve'。
    name : str
        列名前缀。

    Returns
    -------
    pandas.DataFrame
        描述符矩阵。
    """
    enc = encoding.lower()

    if enc == 'ohe':
        return one_hot_encode(df_column, name=name)

    elif enc in ('mordred', 'smiles'):
        descriptor_matrix = mordred(
            df_column.drop_duplicates().values,
            dropna=True,
            name=name,
        )
        # Mordred 编码失败时 fallback 到 OHE
        if len(descriptor_matrix.columns.values) == 1:
            print(f'[edbo_chem] Mordred 编码失败 for {name}，回退到 one-hot 编码')
            return one_hot_encode(df_column, name=name)
        return descriptor_matrix

    elif enc == 'numeric':
        return pd.DataFrame(df_column)

    elif enc == 'resolve':
        names = df_column.drop_duplicates().values
        smiles = [name_to_smiles(s) for s in names]

        failed_indices = [i for i, s in enumerate(smiles) if s == 'FAILED']
        if failed_indices:
            print(f'[edbo_chem] 以下名称无法解析为 SMILES:')
            for i in failed_indices:
                print(f'  ({i}) {names[i]}')
            print(f'[edbo_chem] 回退到 one-hot 编码')
            return one_hot_encode(df_column, name=name)

        return encode_component(
            pd.Series(smiles, name=df_column.name),
            'mordred',
            name=name,
        )

    else:
        raise ValueError(f'未知编码类型: {encoding}')


# ──────────────────────────────────────────────
# 4.5 组分描述符矩阵（供 Ax 等外部引擎消费）
# ──────────────────────────────────────────────

def resolve_descriptor_matrix(values, name='', max_desc=50):
    """把一组化合物标识（SMILES 或化合物名）编码为清洗后、降维、归一化的描述符矩阵。

    与 EDBOplus 的核心思路一致：分子类别变量不直接 One-Hot（丢失结构信息），
    而是用分子描述符编码为连续向量，使高斯过程能感知分子间的结构相似性。

    处理流程：
      1) 解析为 SMILES：能直接解析的保持原样；不能的尝试用 NIH CACTUS 按化合物名查询；
      2) Mordred 全量描述符；
      3) 清洗：去除常量列与非数值列，NaN 用列均值填补（不丢行）；
      4) 降维：维数超过 max_desc 时，按方差保留前 max_desc 个（高方差更具区分度，
         同时把特征维压到 GP 可承受的范围，避免维数灾难）；
      5) MinMax 归一化到 [0, 1]（高斯过程对特征尺度敏感）。

    Parameters
    ----------
    values : list
        化合物标识列表（SMILES 字符串或化合物名）。顺序即为返回矩阵的行顺序。
    name : str
        描述符列名前缀（仅用于日志）。
    max_desc : int
        描述符最大维数（降维上限）。

    Returns
    -------
    dict or None
        成功返回 {
            'values' : 原始标识列表（与矩阵行一一对应），
            'matrix' : np.ndarray (n x D)，每行一个化合物的描述符向量，已归一化到 [0,1]，
            'dim'    : D，
            'failed' : 无法解析/编码的原始标识列表（已用列均值填补，不会丢行），
        }；若全部失败（无有效数值列）返回 None，调用方可回退 One-Hot。
    """
    if not MORDRED_AVAILABLE:
        raise ImportError("mordred is required for resolve descriptors. Install with: pip install mordred")

    values = list(values)
    if len(values) == 0:
        return None

    # 1) 解析为 SMILES
    smiles = []
    failed = []
    for v in values:
        s = str(v)
        mol = None
        try:
            mol = Chem.MolFromSmiles(s)
        except Exception:
            mol = None
        if mol is not None:
            smiles.append(s)
        else:
            resolved = name_to_smiles(s)
            if resolved != 'FAILED' and Chem.MolFromSmiles(resolved) is not None:
                smiles.append(resolved)
            else:
                smiles.append(None)
                failed.append(s)

    # 2) Mordred 全量描述符
    calc = Calculator(descriptors)
    n_desc = len(calc.descriptors)
    raw_rows = []
    for smi in smiles:
        if smi is None:
            raw_rows.append([float('nan')] * n_desc)
        else:
            try:
                d = calc(Chem.MolFromSmiles(smi)).fill_missing()
                raw_rows.append([float(x) for x in d])
            except Exception:
                raw_rows.append([float('nan')] * n_desc)
    df = pd.DataFrame(raw_rows)
    if df.shape[1] == 0:
        return None

    # 3) 清洗：保留至少有两个不同取值的数值列，剔除常量/非数值列
    keep = []
    for c in df.columns:
        col = df[c]
        numeric = col.dropna().apply(lambda x: isinstance(x, (int, float)))
        if numeric.all() and col.nunique(dropna=True) > 1:
            keep.append(c)
    df = df[keep]
    if df.shape[1] == 0:
        return None
    df = df.apply(pd.to_numeric, errors='coerce')
    df = df.fillna(df.mean())

    # 4) 降维：高维时按方差保留前 max_desc 个
    if df.shape[1] > max_desc:
        vars_sorted = df.var().sort_values(ascending=False)
        top = list(vars_sorted.index[:max_desc])
        df = df[top]

    # 5) MinMax 归一化到 [0, 1]
    from sklearn.preprocessing import MinMaxScaler
    scaler = MinMaxScaler()
    mat = scaler.fit_transform(df.values.astype(float))

    if failed:
        print('[edbo_chem] resolve 以下标识无法解析为 SMILES，已用列均值填补: {}'.format(failed))

    return {'values': values, 'matrix': mat, 'dim': mat.shape[1], 'failed': failed}


# ──────────────────────────────────────────────
# 5. 预处理工具
# ──────────────────────────────────────────────

def drop_single_value_columns(df):
    """去除零方差列（只有一种值的列）。"""
    keep = [c for c in df.columns if len(df[c].drop_duplicates()) > 1]
    return df[keep]


def drop_string_columns(df):
    """去除含非数值的列。"""
    keep = []
    for col in df.columns:
        unique = df[col].drop_duplicates()
        if all(type(v) != str for v in unique):
            keep.append(col)
    return df[keep]


def standardize(df, target=None, scaler='minmax'):
    """标准化数值列，保留目标列不变。"""
    from sklearn.preprocessing import MinMaxScaler, StandardScaler

    scaler_obj = MinMaxScaler() if scaler == 'minmax' else StandardScaler()

    if target is not None and target in df.columns:
        data = df.drop(target, axis=1)
    else:
        data = df.copy()

    out = scaler_obj.fit_transform(data)
    new_df = pd.DataFrame(data=out, columns=data.columns)

    if target is not None and target in df.columns:
        new_df[target] = df[target].values

    return new_df


def uncorrelated_features(df, target=None, threshold=0.95):
    """迭代去除高相关特征，保留不相关子集。"""
    if target is not None and target in df.columns:
        data = df.drop(target, axis=1)
    else:
        data = df.copy()

    corr = data.corr().abs()
    keep = []
    for i in range(len(corr.iloc[:, 0])):
        above = corr.iloc[:i, i]
        if len(keep) > 0:
            above = above[keep]
        if len(above[above < threshold]) == len(above):
            keep.append(corr.columns.values[i])

    data = data[keep]
    if target is not None and target in df.columns:
        data[target] = list(df[target])

    return data


# ──────────────────────────────────────────────
# 6. 反应空间构建（简化版，不依赖 Data 容器）
# ──────────────────────────────────────────────

def build_reaction_space(component_dict, encoding=None, clean=True,
                         decorrelate=True, decorrelation_threshold=0.95,
                         standardize_data=True):
    """从组分字典构建编码后的反应空间。

    Parameters
    ----------
    component_dict : dict
        {'组分名': [取值列表], ...}
    encoding : dict, optional
        {'组分名': '编码方式', ...}
        编码方式：'ohe', 'mordred'/'smiles', 'numeric', 'resolve'
    clean : bool
        去除非数值列和零方差列。
    decorrelate : bool
        去除高相关特征。
    standardize_data : bool
        MinMax 标准化到 [0, 1]。

    Returns
    -------
    tuple: (encoded_df, index_df, descriptor_dict)
        encoded_df: 编码后的特征矩阵
        index_df: 原始实验索引（可读的组分取值）
        descriptor_dict: 各组分的描述符矩阵
    """
    if encoding is None:
        encoding = {}

    index_headers = []
    descriptor_dict = {}
    final_values = {}

    for key in component_dict:
        enc = encoding.get(key, 'ohe')
        series = pd.Series(component_dict[key], name=key)
        des = encode_component(series, enc, name=key)

        if clean:
            des = drop_single_value_columns(des)
            des = drop_string_columns(des)

        if decorrelate:
            try:
                des = uncorrelated_features(des, threshold=decorrelation_threshold)
            except Exception:
                pass

        # 第一列是标识列（SMILES / 原始值）
        id_col = des.columns[0]
        id_col_name = id_col + '_index'
        des = des.copy()
        des.insert(0, id_col_name, des.iloc[:, 0])

        descriptor_dict[key] = des
        final_values[key] = des[id_col_name].values
        index_headers.append(id_col_name)

    # 笛卡尔积构建实验索引
    index = pd.DataFrame(
        [row for row in product(*final_values.values())],
        columns=final_values.keys(),
    )
    index = index.drop_duplicates().reset_index(drop=True)

    # 展开为完整特征矩阵
    encoded_rows = []
    for _, row in index.iterrows():
        combined = {}
        for key in index.columns:
            val = row[key]
            des = descriptor_dict[key]
            match = des[des[des.columns[0]] == val]
            if len(match) > 0:
                # 跳过前两列（标识列 + index 列）
                for col in des.columns[2:]:
                    combined[col] = match[col].iloc[0]
        encoded_rows.append(combined)

    encoded_df = pd.DataFrame(encoded_rows)

    if clean:
        encoded_df = drop_single_value_columns(encoded_df)
        encoded_df = drop_string_columns(encoded_df)

    if standardize_data:
        encoded_df = standardize(encoded_df, target=None, scaler='minmax')

    return encoded_df, index, descriptor_dict
