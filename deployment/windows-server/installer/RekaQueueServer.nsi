Unicode True
RequestExecutionLevel admin
SetCompressor zlib
SetCompress off
CRCCheck on

!include "MUI2.nsh"
!include "nsDialogs.nsh"
!include "LogicLib.nsh"
!include "WinMessages.nsh"

!define PRODUCT_NAME "Reka Queue Server"
!define PRODUCT_VERSION "1.0.3"
!define COMPANY_NAME "rekakarsa"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueServer"
!define REPO_ROOT "..\..\.."

Name "${PRODUCT_NAME}"
OutFile "..\RekaQueueServerSetup.exe"
InstallDir "C:\RekaQueue"
InstallDirRegKey HKLM "Software\rekakarsa\RekaQueueServer" "InstallDir"
BrandingText "Reka Queue Management — rekakarsa"
ShowInstDetails show
ShowUninstDetails show

Var AdminName
Var AdminUsername
Var AdminPassword
Var ServerPort
Var UpgradeMode
Var AdminNameField
Var AdminUsernameField
Var AdminPasswordField
Var ServerPortField
Var KeepDataCheckbox
Var KeepData

!define MUI_ABORTWARNING
!define MUI_ICON "${NSISDIR}\Contrib\Graphics\Icons\modern-install.ico"
!define MUI_UNICON "${NSISDIR}\Contrib\Graphics\Icons\modern-uninstall.ico"
!define MUI_FINISHPAGE_RUN "$INSTDIR\Open-RekaQueue.cmd"
!define MUI_FINISHPAGE_RUN_TEXT "Open Reka Queue administrator dashboard"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "${REPO_ROOT}\LICENSE"
!insertmacro MUI_PAGE_DIRECTORY
Page custom ServerOptionsPage ServerOptionsLeave
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_WELCOME
!insertmacro MUI_UNPAGE_CONFIRM
UninstPage custom un.DataPage un.DataPageLeave
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_UNPAGE_FINISH
!insertmacro MUI_LANGUAGE "English"

Function .onInit
    SetRegView 64
    SetShellVarContext all
    StrCpy $AdminName "Administrator"
    StrCpy $AdminUsername "admin"
    StrCpy $ServerPort "8090"
    StrCpy $UpgradeMode "0"
    IfFileExists "$INSTDIR\installation.txt" 0 +2
        StrCpy $UpgradeMode "1"
FunctionEnd

Function ServerOptionsPage
    nsDialogs::Create 1018
    Pop $0
    ${If} $0 == error
        Abort
    ${EndIf}
    ${NSD_CreateLabel} 0 0 100% 24u "Configure the first administrator and network access. Security keys and the database password are generated automatically."
    Pop $0
    ${NSD_CreateLabel} 0 34u 32% 12u "Administrator name"
    Pop $0
    ${NSD_CreateText} 34% 30u 66% 22u "$AdminName"
    Pop $AdminNameField
    ${NSD_CreateLabel} 0 64u 32% 12u "Administrator username"
    Pop $0
    ${NSD_CreateText} 34% 60u 66% 22u "$AdminUsername"
    Pop $AdminUsernameField
    ${NSD_CreateLabel} 0 94u 32% 12u "Administrator password"
    Pop $0
    ${NSD_CreatePassword} 34% 90u 66% 22u "$AdminPassword"
    Pop $AdminPasswordField
    ${NSD_CreateLabel} 0 124u 32% 12u "Server port"
    Pop $0
    ${NSD_CreateNumber} 34% 120u 30% 22u "$ServerPort"
    Pop $ServerPortField
    ${If} $UpgradeMode == "1"
        ${NSD_CreateLabel} 0 154u 100% 28u "Existing Reka Queue data was detected. This setup will upgrade the program and preserve the database, uploads, settings, and accounts. The password field may be left blank."
        Pop $0
    ${Else}
        ${NSD_CreateLabel} 0 154u 100% 28u "Use at least 10 characters for the administrator password. Other devices can connect after Windows Firewall access is configured automatically."
        Pop $0
    ${EndIf}
    nsDialogs::Show
FunctionEnd

Function ServerOptionsLeave
    ${NSD_GetText} $AdminNameField $AdminName
    ${NSD_GetText} $AdminUsernameField $AdminUsername
    ${NSD_GetText} $AdminPasswordField $AdminPassword
    ${NSD_GetText} $ServerPortField $ServerPort
    StrLen $0 $AdminName
    ${If} $0 < 2
        MessageBox MB_ICONEXCLAMATION "Enter the administrator name."
        Abort
    ${EndIf}
    StrLen $0 $AdminUsername
    ${If} $0 < 3
        MessageBox MB_ICONEXCLAMATION "The administrator username must contain at least 3 characters."
        Abort
    ${EndIf}
    ${If} $UpgradeMode != "1"
        StrLen $0 $AdminPassword
        ${If} $0 < 10
            MessageBox MB_ICONEXCLAMATION "The administrator password must contain at least 10 characters."
            Abort
        ${EndIf}
    ${EndIf}
    IntCmp $ServerPort 1 0 port_invalid 0
    IntCmp $ServerPort 65535 0 0 port_invalid
    Return
port_invalid:
    MessageBox MB_ICONEXCLAMATION "Enter a valid server port between 1 and 65535."
    Abort
FunctionEnd

Section "Reka Queue Server" SecMain
    SetShellVarContext all
    SetRegView 64
    SetOutPath "$PLUGINSDIR\reka-bundle\payload\app"
    File /r "${REPO_ROOT}\app"
    File /r "${REPO_ROOT}\bin"
    File /r "${REPO_ROOT}\bootstrap"
    File /r "${REPO_ROOT}\database"
    File /r /x uploads "${REPO_ROOT}\public"
    File "${REPO_ROOT}\.env.example"
    SetOutPath "$PLUGINSDIR\reka-bundle\runtime"
    File "..\runtime\xampp-portable-windows-x64-8.2.12-0-VS16.zip"
    File "..\runtime\vc_redist.x64.exe"
    SetOutPath "$PLUGINSDIR\reka-bundle\scripts"
    File "..\scripts\install.ps1"
    File "..\scripts\backup.ps1"
    File "..\scripts\uninstall.ps1"

    FileOpen $0 "$PLUGINSDIR\reka-options.ini" w
    FileWrite $0 "[INSTALL]$\r$\n"
    FileWrite $0 "INSTALL_DIR=$INSTDIR$\r$\n"
    FileWrite $0 "SERVER_IP=auto$\r$\n"
    FileWrite $0 "SERVER_PORT=$ServerPort$\r$\n"
    FileWrite $0 "ADMIN_NAME=$AdminName$\r$\n"
    FileWrite $0 "ADMIN_USERNAME=$AdminUsername$\r$\n"
    FileWrite $0 "ADMIN_PASSWORD=$AdminPassword$\r$\n"
    FileClose $0

    DetailPrint "Installing the bundled web and database runtime..."
    nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$PLUGINSDIR\reka-bundle\scripts\install.ps1" -PackageRoot "$PLUGINSDIR\reka-bundle" -OptionsFile "$PLUGINSDIR\reka-options.ini"'
    Pop $0
    ${If} $0 != 0
        MessageBox MB_ICONSTOP "Reka Queue installation failed with code $0. Review the installation log for details."
        Abort
    ${EndIf}

    SetOutPath "$INSTDIR"
    FileOpen $0 "$INSTDIR\Open-RekaQueue.cmd" w
    FileWrite $0 '@echo off$\r$\n'
    FileWrite $0 'for /f "tokens=2 delims==" %%A in ($\'findstr /b "URL=" "$INSTDIR\installation.txt"$\') do start "" "%%A/dashboard"$\r$\n'
    FileClose $0
    WriteUninstaller "$INSTDIR\Uninstall.exe"
    WriteRegStr HKLM "Software\rekakarsa\RekaQueueServer" "InstallDir" "$INSTDIR"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayName" "${PRODUCT_NAME}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayVersion" "${PRODUCT_VERSION}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "Publisher" "${COMPANY_NAME}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayIcon" "$INSTDIR\Uninstall.exe"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\Uninstall.exe"'
    WriteRegStr HKLM "${UNINSTALL_KEY}" "QuietUninstallString" '"$INSTDIR\Uninstall.exe" /S'
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoModify" 1
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 0
SectionEnd

Function un.onInit
    SetRegView 64
    StrCpy $KeepData "1"
FunctionEnd

Function un.DataPage
    nsDialogs::Create 1018
    Pop $0
    ${NSD_CreateLabel} 0 0 100% 30u "Choose whether operational data should remain available for a future reinstall. A final database backup is attempted before services are removed."
    Pop $0
    ${NSD_CreateCheckbox} 0 44u 100% 18u "Keep database, settings, uploaded media, audio, and backups (recommended)"
    Pop $KeepDataCheckbox
    ${NSD_Check} $KeepDataCheckbox
    nsDialogs::Show
FunctionEnd

Function un.DataPageLeave
    ${NSD_GetState} $KeepDataCheckbox $KeepData
FunctionEnd

Section "Uninstall"
    SetShellVarContext all
    ${If} $KeepData == ${BST_CHECKED}
        nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\tools\uninstall.ps1" -InstallDir "$INSTDIR"'
    ${Else}
        nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\tools\uninstall.ps1" -InstallDir "$INSTDIR" -RemoveData'
    ${EndIf}
    Pop $0
    DeleteRegKey HKLM "${UNINSTALL_KEY}"
    DeleteRegKey HKLM "Software\rekakarsa\RekaQueueServer"
    RMDir /r "$INSTDIR"
SectionEnd
