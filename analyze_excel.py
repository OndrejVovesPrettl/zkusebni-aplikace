import pandas as pd
import sys

file_path = r"C:\laragon\www\pisek\v3.4\uploads\tmp\Denní výroba v04.xlsm"

try:
    xl = pd.ExcelFile(file_path, engine='openpyxl')
    print(f"File: {file_path}")
    print(f"Sheet names: {xl.sheet_names}\n")

    for sheet_name in xl.sheet_names:
        df = xl.parse(sheet_name)
        print(f"--- Sheet: {sheet_name} ---")
        print(f"Dimensions: {df.shape}")
        print(f"Column Headers: {df.columns.tolist()}")
        print("First 5 rows:")
        print(df.head())
        print("\n")
except Exception as e:
    print(f"Error: {e}")
