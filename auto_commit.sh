#!/bin/bash
# auto_commit.sh - Script untuk commit otomatis ke GitHub

# Ganti dengan path absolut proyek Anda
cd /home/wwwroot/greening

# Tambahkan semua perubahan
git add .

# Cek apakah ada perubahan yang perlu di-commit
if git diff --cached --quiet; then
    echo "Tidak ada perubahan untuk di-commit."
    exit 0
fi

# Buat commit dengan timestamp
git commit -m "Auto commit: $(date '+%Y-%m-%d %H:%M:%S')"

# Push ke GitHub
git push origin main
