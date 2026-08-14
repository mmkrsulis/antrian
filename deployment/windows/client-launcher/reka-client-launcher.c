#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <shellapi.h>
#include <stdio.h>
#include <string.h>

#ifndef CLIENT_DISPLAY
#define CLIENT_DISPLAY 0
#endif

static void executable_directory(char *directory,size_t capacity,char *executable,size_t executable_capacity){
    GetModuleFileNameA(NULL,executable,(DWORD)executable_capacity);
    strncpy(directory,executable,capacity-1);directory[capacity-1]=0;
    char *slash=strrchr(directory,'\\');if(slash)*slash=0;
}

static int existing_path(char *output,size_t capacity,const char *environment,const char *suffix){
    char base[MAX_PATH];DWORD length=GetEnvironmentVariableA(environment,base,sizeof(base));
    if(!length||length>=sizeof(base))return 0;
    snprintf(output,capacity,"%s%s",base,suffix);
    return GetFileAttributesA(output)!=INVALID_FILE_ATTRIBUTES;
}

static const char *find_browser(char *path,size_t capacity,int *firefox){
    *firefox=0;
    if(existing_path(path,capacity,"ProgramFiles(x86)","\\Microsoft\\Edge\\Application\\msedge.exe"))return "Edge";
    if(existing_path(path,capacity,"ProgramFiles","\\Microsoft\\Edge\\Application\\msedge.exe"))return "Edge";
    if(existing_path(path,capacity,"ProgramFiles","\\Google\\Chrome\\Application\\chrome.exe"))return "Chrome";
    if(existing_path(path,capacity,"ProgramFiles(x86)","\\Google\\Chrome\\Application\\chrome.exe"))return "Chrome";
    if(existing_path(path,capacity,"LOCALAPPDATA","\\Google\\Chrome\\Application\\chrome.exe"))return "Chrome";
    if(existing_path(path,capacity,"ProgramFiles","\\Mozilla Firefox\\firefox.exe")){*firefox=1;return "Firefox";}
    if(existing_path(path,capacity,"ProgramFiles(x86)","\\Mozilla Firefox\\firefox.exe")){*firefox=1;return "Firefox";}
    return NULL;
}

static int register_startup(const char *executable){
    HKEY key;char command[1200];snprintf(command,sizeof(command),"\"%s\"",executable);
    if(RegCreateKeyExA(HKEY_CURRENT_USER,"Software\\Microsoft\\Windows\\CurrentVersion\\Run",0,NULL,0,KEY_SET_VALUE,NULL,&key,NULL)!=ERROR_SUCCESS)return 0;
    const char *name=CLIENT_DISPLAY?"RekaQueueDisplayClient":"RekaQueueOperatorClient";
    LONG result=RegSetValueExA(key,name,0,REG_SZ,(const BYTE*)command,(DWORD)strlen(command)+1);RegCloseKey(key);return result==ERROR_SUCCESS;
}

int WINAPI WinMain(HINSTANCE instance,HINSTANCE previous,LPSTR command_line,int show){
    (void)instance;(void)previous;(void)command_line;(void)show;
    char directory[1024],executable[1024],ini[1200],server[512],display_key[256],auto_start[16],monitor_x[32],browser[1024],url[1024],arguments[4096],local_app_data[1024],profile[1200];int firefox=0;
    executable_directory(directory,sizeof(directory),executable,sizeof(executable));
    snprintf(ini,sizeof(ini),"%s\\reka-queue-config.ini",directory);
    GetPrivateProfileStringA("client","SERVER_URL","http://server-address:8090",server,sizeof(server),ini);
    GetPrivateProfileStringA("client","DISPLAY_KEY","change-this-display-key",display_key,sizeof(display_key),ini);
    GetPrivateProfileStringA("client","MONITOR_X","1920",monitor_x,sizeof(monitor_x),ini);
    GetPrivateProfileStringA("client","AUTO_START","1",auto_start,sizeof(auto_start),ini);
    size_t length=strlen(server);while(length&&server[length-1]=='/')server[--length]=0;
    if(!strncmp(auto_start,"1",1))register_startup(executable);
    const char *browser_name=find_browser(browser,sizeof(browser),&firefox);
    if(!browser_name){MessageBoxA(NULL,"Install Microsoft Edge, Google Chrome, or Mozilla Firefox first.","Reka Queue Client",MB_OK|MB_ICONERROR);return 1;}
    GetEnvironmentVariableA("LOCALAPPDATA",local_app_data,sizeof(local_app_data));
    snprintf(profile,sizeof(profile),"%s\\RekaQueue\\%sProfile",local_app_data,CLIENT_DISPLAY?"Display":"Operator");
    if(CLIENT_DISPLAY)snprintf(url,sizeof(url),"%s/display?key=%s",server,display_key);else snprintf(url,sizeof(url),"%s/operator?client=operator",server);
    if(firefox){
        if(CLIENT_DISPLAY)snprintf(arguments,sizeof(arguments),"--kiosk \"%s\"",url);else snprintf(arguments,sizeof(arguments),"-new-window \"%s\"",url);
    }else if(CLIENT_DISPLAY){
        snprintf(arguments,sizeof(arguments),"--user-data-dir=\"%s\" --kiosk \"%s\" --edge-kiosk-type=fullscreen --autoplay-policy=no-user-gesture-required --no-first-run --disable-session-crashed-bubble --window-position=%s,0",profile,url,monitor_x);
    }else{
        snprintf(arguments,sizeof(arguments),"--user-data-dir=\"%s\" --app=\"%s\" --start-maximized --no-first-run --no-default-browser-check --disable-session-crashed-bubble",profile,url);
    }
    HINSTANCE launched=ShellExecuteA(NULL,"open",browser,arguments,directory,SW_SHOWNORMAL);
    if((INT_PTR)launched<=32){char message[768];snprintf(message,sizeof(message),"Unable to open %s with %s. Check SERVER_URL in reka-queue-config.ini.",CLIENT_DISPLAY?"display":"operator client",browser_name);MessageBoxA(NULL,message,"Reka Queue Client",MB_OK|MB_ICONERROR);return 1;}
    return 0;
}
