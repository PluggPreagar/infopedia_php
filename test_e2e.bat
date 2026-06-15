@echo off
:: Run unit tests + E2E tests using the local PHP interpreter (no server needed).
:: Usage:
::   test_e2e.bat           unit + e2e
::   test_e2e.bat --debug   verbose e2e output

set PHP=D:\_progs\xampp\php\php.exe

echo PHP: %PHP%
echo.

echo === Unit tests ===
%PHP% test\run_all.php
set UNIT=%ERRORLEVEL%

echo.
echo === E2E tests ===
%PHP% test\e2e.php %*
set E2E=%ERRORLEVEL%

echo.
if %UNIT%==0 if %E2E%==0 ( echo ALL PASS & exit /b 0 )
echo FAILED (unit=%UNIT% e2e=%E2E%) & exit /b 1
