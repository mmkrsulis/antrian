Unicode true
!include "MUI2.nsh"

Name "Reka Queue Notifier"
OutFile "../../RekaQueueNotifierSetup.exe"
InstallDir "$LOCALAPPDATA\Programs\Reka Queue Notifier"
InstallDirRegKey HKCU "Software\RekaQueueNotifier" "InstallLocation"
RequestExecutionLevel user
SetCompressor /SOLID lzma
BrandingText "rekakarsa · Sulis Setiyawan"

VIProductVersion "1.4.0.0"
VIAddVersionKey "ProductName" "Reka Queue Notifier"
VIAddVersionKey "CompanyName" "rekakarsa"
VIAddVersionKey "FileDescription" "Reka Queue Notifier Installer"
VIAddVersionKey "FileVersion" "1.4.0"
VIAddVersionKey "ProductVersion" "1.4.0"
VIAddVersionKey "LegalCopyright" "Created by Sulis Setiyawan"

!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE "Instal Reka Queue Notifier"
!define MUI_WELCOMEPAGE_TEXT "Wizard ini akan memasang aplikasi notifikasi operator, mengaktifkan startup Windows, dan menyediakan uninstaller resmi.$\r$\n$\r$\nKlik Lanjut untuk melanjutkan."
!define MUI_FINISHPAGE_RUN "$INSTDIR\RekaQueueNotifier.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Buka pengaturan koneksi sekarang"
!define MUI_FINISHPAGE_LINK "Kunjungi rekadev.site"
!define MUI_FINISHPAGE_LINK_LOCATION "https://rekadev.site"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_UNPAGE_FINISH
!insertmacro MUI_LANGUAGE "Indonesian"

Section "Reka Queue Notifier" SEC_MAIN
 SetShellVarContext current
 SetOutPath "$INSTDIR"
 File "RekaQueueNotifier.exe"
 File "README.txt"
 SetOverwrite off
 File "reka-notifier.ini"
 SetOverwrite on
 WriteUninstaller "$INSTDIR\Uninstall.exe"
 WriteRegStr HKCU "Software\RekaQueueNotifier" "InstallLocation" "$INSTDIR"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "RekaQueueNotifier" '"$INSTDIR\RekaQueueNotifier.exe"'
 CreateDirectory "$SMPROGRAMS\Reka Queue Notifier"
 CreateShortcut "$SMPROGRAMS\Reka Queue Notifier\Reka Queue Notifier.lnk" "$INSTDIR\RekaQueueNotifier.exe"
 CreateShortcut "$SMPROGRAMS\Reka Queue Notifier\Uninstall.lnk" "$INSTDIR\Uninstall.exe"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "DisplayName" "Reka Queue Notifier"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "DisplayVersion" "1.4.0"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "Publisher" "Sulis Setiyawan - rekakarsa"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "DisplayIcon" "$INSTDIR\RekaQueueNotifier.exe"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "InstallLocation" "$INSTDIR"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "URLInfoAbout" "https://rekadev.site"
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "UninstallString" '"$INSTDIR\Uninstall.exe"'
 WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "QuietUninstallString" '"$INSTDIR\Uninstall.exe" /S'
 WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "NoModify" 1
 WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier" "NoRepair" 1
SectionEnd

Section "Uninstall"
 SetShellVarContext current
 ExecWait 'taskkill /IM RekaQueueNotifier.exe /F'
 DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "RekaQueueNotifier"
 DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\RekaQueueNotifier"
 DeleteRegKey HKCU "Software\RekaQueueNotifier"
 Delete "$SMPROGRAMS\Reka Queue Notifier\Reka Queue Notifier.lnk"
 Delete "$SMPROGRAMS\Reka Queue Notifier\Uninstall.lnk"
 RMDir "$SMPROGRAMS\Reka Queue Notifier"
 Delete "$INSTDIR\RekaQueueNotifier.exe"
 Delete "$INSTDIR\reka-notifier.ini"
 Delete "$INSTDIR\README.txt"
 Delete "$INSTDIR\Uninstall.exe"
 RMDir "$INSTDIR"
SectionEnd
