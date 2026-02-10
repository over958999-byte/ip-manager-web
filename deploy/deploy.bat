@echo off
chcp 65001 >nul
title IP管理器 - 快速部署工具

:: ===== 服务器配置 =====
set SERVER=38.14.208.66
set PORT=33876
set USER=root
set REMOTE_PATH=/var/www/ipadmin/

:: ===== 本地路径 =====
set LOCAL_PATH=%~dp0

echo ========================================
echo    IP管理器 - 快速部署工具
echo ========================================
echo.
echo 服务器: %SERVER%:%PORT%
echo 远程路径: %REMOTE_PATH%
echo 本地路径: %LOCAL_PATH%
echo.

:: 选择部署模式
echo 请选择部署模式:
echo [1] 部署核心文件 (index.php, antibot.php, bad_ips.php)
echo [2] 部署所有PHP文件
echo [3] 部署全部文件 (包括配置)
echo [4] 仅部署 index.php
echo [5] 自定义文件
echo [0] 退出
echo.
set /p choice=请输入选项 (1-5): 

if "%choice%"=="0" goto :end
if "%choice%"=="1" goto :core
if "%choice%"=="2" goto :allphp
if "%choice%"=="3" goto :all
if "%choice%"=="4" goto :index
if "%choice%"=="5" goto :custom
goto :core

:core
echo.
echo [部署核心文件...]
scp -o StrictHostKeyChecking=no -P %PORT% "%LOCAL_PATH%index.php" "%LOCAL_PATH%antibot.php" "%LOCAL_PATH%bad_ips.php" %USER%@%SERVER%:%REMOTE_PATH%
goto :verify

:allphp
echo.
echo [部署所有PHP文件...]
scp -o StrictHostKeyChecking=no -P %PORT% "%LOCAL_PATH%*.php" %USER%@%SERVER%:%REMOTE_PATH%
goto :verify

:all
echo.
echo [部署全部文件...]
scp -o StrictHostKeyChecking=no -P %PORT% "%LOCAL_PATH%*.php" "%LOCAL_PATH%*.conf" %USER%@%SERVER%:%REMOTE_PATH%
goto :verify

:index
echo.
echo [部署 index.php...]
scp -o StrictHostKeyChecking=no -P %PORT% "%LOCAL_PATH%index.php" %USER%@%SERVER%:%REMOTE_PATH%
goto :verify

:custom
echo.
set /p files=请输入文件名 (多个用空格分隔): 
echo [部署自定义文件: %files%...]
for %%f in (%files%) do (
    scp -o StrictHostKeyChecking=no -P %PORT% "%LOCAL_PATH%%%f" %USER%@%SERVER%:%REMOTE_PATH%
)
goto :verify

:verify
echo.
if %errorlevel%==0 (
    echo ========================================
    echo    ✓ 部署成功!
    echo ========================================
    echo.
    echo [验证远程文件...]
    ssh -o StrictHostKeyChecking=no -p %PORT% %USER%@%SERVER% "ls -la %REMOTE_PATH%*.php | tail -6"
) else (
    echo ========================================
    echo    ✗ 部署失败! 错误码: %errorlevel%
    echo ========================================
)

:end
echo.
pause
