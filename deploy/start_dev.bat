@echo off
chcp 65001 >nul
title 困King分发平台 - 开发服务器

echo ========================================
echo    困King分发平台 - 开发服务器
echo ========================================
echo.

:: 检查 MySQL 服务
echo [1/3] 检查 MySQL 服务...
sc query MySQL | find "RUNNING" >nul
if %errorlevel% neq 0 (
    echo MySQL 未运行，正在启动...
    net start MySQL
) else (
    echo MySQL 已运行
)

:: 启动 PHP 服务器
echo.
echo [2/3] 启动 PHP 后端服务器 (端口 8080)...
cd /d "%~dp0.."
start "PHP Server" cmd /c "php -S localhost:8080 2>&1"
timeout /t 2 /nobreak >nul

:: 启动前端服务器
echo.
echo [3/3] 启动前端开发服务器 (端口 3000)...
cd /d "%~dp0..\backend\frontend"
start "Frontend Server" cmd /c "npm run dev"

echo.
echo ========================================
echo 服务器启动完成！
echo.
echo 管理后台: http://localhost:3000
echo PHP服务: http://localhost:8080
echo 用户入口: http://localhost:8080/public/
echo.
echo 默认密码: admin123
echo ========================================
echo.
echo 按任意键退出此窗口（服务器会继续运行）...
pause >nul
