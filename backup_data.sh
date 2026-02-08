#!/usr/bin/env bash

# 年 (YYYY) と 月日 (MMDD)
YEAR=$(date +"%Y")
MD=$(date +"%m%d")

# バックアップ先ディレクトリ
BACKUP_DIR="./data_backup/${YEAR}"

# バックアップ先ファイル名
BACKUP_FILE="${BACKUP_DIR}/${MD}.zip"

# ディレクトリ作成（無ければ）
mkdir -p "${BACKUP_DIR}"

# ./data フォルダを zip 圧縮
zip -r "${BACKUP_FILE}" ./data


