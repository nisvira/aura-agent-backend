# กู้คืน Aura Backend จาก backup

Backup อยู่ที่ `/home/yruadmin/backups/aura-backend/` (สร้างโดย `deploy/backup.sh` ทุกวัน 02:30 · เก็บ 14 วัน · log: `backup.log`)

| ไฟล์ | เนื้อหา |
|---|---|
| `db-<เวลา>.sql.gz` | ฐานข้อมูล `aura_agent_db` ทั้งหมด |
| `storage-<เวลา>.tar.gz` | volume `aura-agent-backend_storage` (log ของระบบ) |
| `env-<เวลา>` | ไฟล์ `.env` (มีรหัสผ่านและ key — ห้ามส่งต่อ) |
| `SHA256SUMS-<เวลา>` | checksum ของ 3 ไฟล์ข้างบน |

## ทดสอบก่อนกู้จริง (ไม่กระทบระบบ)

```sh
cd /home/yruadmin/aura-agent-backend
./deploy/restore-test.sh                                   # backup ล่าสุด
./deploy/restore-test.sh ~/backups/aura-backend/db-20261026-092751.sql.gz
```

กู้ลง MySQL ชั่วคราวแล้วเทียบจำนวนแถวกับของจริง ต้องจบด้วย `RESTORE TEST PASSED`

## กู้คืนจริง

ใช้เมื่อฐานข้อมูลเสียหายหรือข้อมูลหาย **ข้อมูลหลังเวลาของ backup จะหายไป**

```sh
cd /home/yruadmin/aura-agent-backend
B=~/backups/aura-backend; S=20261026-092751          # เลือก backup ที่จะใช้
(cd $B && sha256sum -c SHA256SUMS-$S)                # 1. ตรวจว่าไฟล์ไม่เสีย

docker compose stop                                  # 2. หยุด backend กันข้อมูลเขียนทับระหว่างกู้
./deploy/backup.sh                                   # 3. เก็บสถานะปัจจุบันไว้ก่อน (เผื่อต้องย้อนกลับ)

# 4. กู้ฐานข้อมูล (ทับของเดิมทั้งหมด)
set -a; . ./.env; set +a
gunzip -c $B/db-$S.sql.gz | docker exec -i -e MYSQL_PWD="$DB_PASSWORD" mysql_database mysql -u"$DB_USERNAME" "$DB_DATABASE"

# 5. (ถ้าต้องการ) กู้ log
docker run --rm -v aura-agent-backend_storage:/data -v $B:/backup --entrypoint sh aura-agent-backend:latest \
  -c "rm -rf /data/* && tar xzf /backup/storage-$S.tar.gz -C /data"

# 6. (ถ้า .env หาย) cp $B/env-$S .env && chmod 600 .env

docker compose up -d                                 # 7. เปิดระบบ (migration รันให้เอง)
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up   # ต้องได้ 200
```

## ย้ายไปเครื่องใหม่

1. ติดตั้ง Docker, MySQL 8 (container `mysql_database`) และ clone โปรเจคนี้
2. วาง `env-<เวลา>` เป็น `.env` แก้ `DB_HOST`, `APP_URL` ให้ตรงเครื่องใหม่
3. `docker compose up -d --build` (สร้างตารางว่าง) แล้วทำขั้นตอน 4–7 ข้างบน

ข้อควรรู้: backup อยู่บนดิสก์เดียวกับเซิร์ฟเวอร์ ถ้าดิสก์เสียจะหายพร้อมกัน ควรคัดลอก `~/backups/aura-backend` ไปเก็บที่อื่นเป็นระยะ
