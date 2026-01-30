# Flarum Migration Tool - Complete Admin UI

Extension Flarum với giao diện admin đầy đủ để sao lưu và di chuyển forum sang server mới một cách tự động.

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![Flarum](https://img.shields.io/badge/flarum-%5E1.0-orange)
![License](https://img.shields.io/badge/license-MIT-green)

## ✨ Tính năng

### Tab Backup (Sao lưu)
- ✅ Tạo backup đầy đủ qua giao diện admin
- ✅ Tùy chọn bao gồm/loại trừ uploads và vendor
- ✅ Danh sách tất cả các backup với kích thước và ngày tạo
- ✅ Download backup trực tiếp
- ✅ Xóa backup không cần thiết
- ✅ Tự động tạo file ZIP chứa:
  - Database (SQL)
  - Settings (JSON)
  - Config.php
  - Composer.json & composer.lock
  - Uploaded files (avatars, attachments)
  - Extensions data
  - Migration script tự động
  - Metadata đầy đủ

### Tab Migrate (Di chuyển)
- ✅ Upload file backup ZIP qua giao diện
- ✅ Cấu hình database và URL mới ngay trong admin
- ✅ Tự động import database
- ✅ Tự động copy files
- ✅ Tự động chạy composer install
- ✅ Tự động cập nhật config.php
- ✅ Tự động chạy migrations
- ✅ Cảnh báo rõ ràng về việc ghi đè dữ liệu

### Tab Logs (Nhật ký)
- ✅ Xem real-time logs của quá trình migration
- ✅ Track mọi bước trong quá trình
- ✅ Debug dễ dàng nếu có lỗi

## 📦 Cài đặt

### 1. Cài đặt Extension

```bash
composer require nauhyuh/flarum-migration-tool
```

### 2. Build Frontend Assets

```bash
cd vendor/nauhyuh/flarum-migration-tool
npm install
npm run build
```

### 3. Enable Extension

```bash
php flarum extension:enable nauhyuh-migration-tool
```

Hoặc enable qua Admin Panel → Extensions

## 🚀 Sử dụng

### A. Trên Server Cũ - Tạo Backup

1. Đăng nhập Admin Panel
2. Vào **Extensions** → **Migration Tool**
3. Chọn tab **Backup**
4. Tùy chọn:
   - ☑️ Include uploaded files (nên chọn)
   - ☐ Include vendor (không khuyến khích - sẽ làm file rất lớn)
5. Click **Create Backup**
6. Đợi quá trình hoàn tất (có thể mất vài phút)
7. Download file ZIP từ danh sách backups

### B. Trên Server Mới - Restore & Migrate

#### Cách 1: Sử dụng Giao diện Admin (Khuyến nghị)

1. Cài đặt Flarum mới trên server mới:
```bash
composer create-project flarum/flarum .
```

2. Cài đặt extension này:
```bash
composer require nauhyuh/flarum-migration-tool
php flarum extension:enable nauhyuh-migration-tool
```

3. Đăng nhập Admin Panel
4. Vào **Extensions** → **Migration Tool**
5. Chọn tab **Migrate**
6. Upload file backup ZIP đã download từ server cũ
7. Điền thông tin:
   - **New Site URL**: `https://your-new-domain.com`
   - **Database Host**: `localhost`
   - **Database Name**: `your_database`
   - **Database Username**: `your_user`
   - **Database Password**: `your_password`
8. Click **Start Migration**
9. Đợi quá trình hoàn tất
10. Kiểm tra tab **Logs** để xem chi tiết

#### Cách 2: Sử dụng Script Tự động (Nếu admin không khả dụng)

File backup đã chứa sẵn script `migrate.sh`:

```bash
# Giải nén backup
unzip flarum_backup_2026-01-30_12-00-00.zip -d migration_temp

# Chạy script migration
cd migration_temp
chmod +x migrate.sh
./migrate.sh
```

Script sẽ tự động:
1. Hỏi thông tin database
2. Import database
3. Copy files
4. Cài đặt dependencies
5. Cập nhật config
6. Chạy migrations

## 📋 Quy trình Di chuyển Chi tiết

### Trên Server Cũ:

```
1. Login Admin
2. Extensions → Migration Tool → Tab Backup
3. Chọn options → Create Backup
4. Download file ZIP
```

### Trên Server Mới:

```
1. Cài Flarum mới
2. Cài extension này
3. Login Admin → Extensions → Migration Tool
4. Tab Migrate → Upload ZIP
5. Điền config → Start Migration
6. Kiểm tra Logs
7. Test website
```

## 🛠️ Cấu trúc Backup ZIP

```
flarum_backup_YYYY-MM-DD_HH-MM-SS.zip
├── database.sql              # Full database dump
├── settings.json             # All Flarum settings
├── config.php               # Config file
├── composer.json            # Composer dependencies
├── composer.lock           
├── .env                     # Environment file (if exists)
├── metadata.json            # Backup metadata
├── migrate.sh              # Auto migration script
├── assets/                 # Uploaded files
│   ├── avatars/
│   └── files/
└── storage/                # Extensions data
    └── extensions/
```

## 📊 Metadata

File `metadata.json` chứa thông tin:

```json
{
  "version": "2.0",
  "backup_date": "2026-01-30 14:30:00",
  "flarum_version": "1.8.5",
  "php_version": "8.2.0",
  "mysql_version": "8.0.35",
  "include_uploads": true,
  "include_vendor": false,
  "database_name": "flarum",
  "url": "https://old-domain.com",
  "extensions": [
    {
      "name": "flarum/tags",
      "version": "^1.8",
      "enabled": true
    }
  ],
  "server_info": {
    "os": "Linux",
    "web_server": "nginx/1.24.0"
  }
}
```

## 🔒 Bảo mật

- ⚠️ File backup chứa **toàn bộ database** bao gồm mật khẩu (đã hash)
- ⚠️ File backup chứa **API keys** và **secrets** từ config
- ⚠️ Không upload backup lên nơi công khai
- ⚠️ Xóa backup cũ không còn sử dụng
- ⚠️ Sử dụng HTTPS khi upload/download backup

## 🐛 Troubleshooting

### Lỗi: "Cannot create backup"
```bash
# Kiểm tra quyền
chmod -R 775 storage
chown -R www-data:www-data storage

# Kiểm tra disk space
df -h
```

### Lỗi: "Upload failed"
```bash
# Tăng upload limit trong php.ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 300
```

### Lỗi: "Migration failed"
```bash
# Kiểm tra logs
cat storage/logs/migration.log

# Kiểm tra database credentials
php flarum info
```

### Lỗi: "Composer install failed"
```bash
# Chạy thủ công
composer install --no-dev --optimize-autoloader

# Hoặc dùng memory limit cao hơn
php -d memory_limit=512M $(which composer) install
```

### Frontend không build được
```bash
cd vendor/nauhyuh/flarum-migration-tool
rm -rf node_modules
npm install
npm run build
```

## 📝 API Endpoints

Extension cung cấp các API endpoints:

```
POST   /api/migration/backup/create       # Tạo backup
GET    /api/migration/backup/list         # List backups
GET    /api/migration/backup/download/:f  # Download backup
DELETE /api/migration/backup/delete/:f    # Delete backup
POST   /api/migration/upload               # Upload backup
POST   /api/migration/migrate              # Start migration
GET    /api/migration/status               # Get status
GET    /api/migration/logs                 # Get logs
POST   /api/migration/config/update        # Update config
```

## 🎨 Screenshots

*Thêm screenshots của admin UI ở đây*

## 📄 License

MIT License - xem file [LICENSE](LICENSE)

## 🤝 Đóng góp

Contributions, issues và feature requests đều được chào đón!

## ⚡ Performance Tips

1. **Backup lớn**: Nếu forum có nhiều uploads (>1GB):
   - Backup không bao gồm uploads
   - Sync uploads riêng bằng rsync:
   ```bash
   rsync -avz public/assets/ user@new-server:/path/to/flarum/public/assets/
   ```

2. **Composer**: Không nên backup vendor folder:
   - Dùng `composer install` trên server mới nhanh hơn
   - Giảm kích thước backup đáng kể

3. **Database lớn**: Nếu database >100MB:
   - Xem xét tăng timeout trong nginx/Apache
   - Có thể export database thủ công nếu cần

## 📞 Support

- GitHub Issues: [Link to repo]
- Flarum Community: [Link to discussion]
- Email: your-email@example.com

---

Made with ❤️ for the Flarum community
