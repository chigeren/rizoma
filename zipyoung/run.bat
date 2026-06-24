@echo off
chcp 65001 >nul
color F0
cls
set MODE=%1
if "%MODE%"=="" set MODE=oneshot
echo zipyoung [%MODE%]
echo.
"%~dp0php\php.exe" -c "%~dp0php\php.ini" "%~dp0zipyoung.php" %MODE%
if "%MODE%"=="oneshot" echo. && pause
