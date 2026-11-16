# Локальный сайт с системой заявок: боевая сборка в C:\Users\anton\dev\virazh-deploy-test + CRM из папки crm/ репозитория.
# Почта, SMS и MAX не отправляются — пишутся в crm/storage/logs/. База — crm/storage/database.sqlite (создаётся сама).
#   perl tools/build.pl --out=C:/Users/anton/dev/virazh-deploy-test
#   powershell -ExecutionPolicy Bypass -File tools/dev-server.ps1        → http://localhost:8796, админка /crm
#   php crm/database/create-manager.php director "Имя"                    → первый руководитель (логин и пароль покажет)
$repo = Split-Path -Parent $PSScriptRoot
$env:VIRAZH_CRM = Join-Path $repo 'crm'
php -S 127.0.0.1:8796 -t C:\Users\anton\dev\virazh-deploy-test (Join-Path $PSScriptRoot 'dev-router.php')
