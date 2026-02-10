@echo off
REM 使用密钥免密登录服务器
set KEY_FILE=%~dp0..\keys\server_key
set SERVER=ubuntu@43.157.159.31

if "%1"=="" (
    echo 连接到服务器...
    ssh -i "%KEY_FILE%" %SERVER%
) else (
    ssh -i "%KEY_FILE%" %SERVER% %*
)
