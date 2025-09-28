@echo off
REM ====== GitHub Auto Upload Script ======
REM User: swaponmahmud
REM Email: swapon9124@gmail.com
REM Repo:  isp_billing
REM Local: C:\Users\SWAPON\Desktop\isp_billing

cd /d C:\Users\SWAPON\Desktop\isp_billing

:: Set Git config (only needed first time)
git config user.name "swaponmahmud"
git config user.email "swapon9124@gmail.com"

:: Initialize repo if not already done
if not exist ".git" (
    git init
    git branch -M main
)

:: Add all files
git add .

:: Commit changes (always with current datetime)
git commit -m "Auto commit - %date% %time%"

:: Add remote (only if not already added)
git remote | find "origin" >nul
if errorlevel 1 (
    git remote add origin https://github.com/swaponmahmud/isp_billing.git
)

:: Push to GitHub
git push -u origin main

echo.
echo ===============================
echo  Upload to GitHub completed ✅
echo ===============================
pause
