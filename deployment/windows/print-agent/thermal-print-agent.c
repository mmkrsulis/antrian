#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <winsock2.h>
#include <winspool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define PORT 17321
#define REQUEST_MAX 16384

static int json_value(const char *json,const char *key,char *out,size_t cap){char pattern[96],*p;size_t n=0;snprintf(pattern,sizeof(pattern),"\"%s\"",key);p=strstr(json,pattern);if(!p)return 0;p=strchr(p+strlen(pattern),':');if(!p)return 0;while(*++p==' ');if(*p!='\"')return 0;p++;while(*p&&*p!='\"'&&n+1<cap){if(*p=='\\'&&p[1]){p++;if(*p=='n')out[n++]='\n';else if(*p=='r')out[n++]='\r';else if(*p=='t')out[n++]=' ';else if(*p=='u'){for(int i=0;i<4&&p[1];i++)p++;}else out[n++]=*p;p++;continue;}unsigned char c=(unsigned char)*p++;if(c>=32&&c<=126)out[n++]=(char)c;}out[n]=0;return 1;}
static void add_bytes(unsigned char *buf,size_t *n,const unsigned char *value,size_t length){while(length--&&*n<4095)buf[(*n)++]=*value++;}
static void add_text(unsigned char *buf,size_t *n,const char *text){while(*text&&*n<4095){unsigned char c=(unsigned char)*text++;if(c=='\n'||(c>=32&&c<=126))buf[(*n)++]=c;}}
static void add_wrapped_text(unsigned char *buf,size_t *n,const char *text,size_t width){
    char line[64];size_t line_length=0;
    while(*text){
        if(*text=='\r'){text++;continue;}
        if(*text=='\n'){line[line_length]=0;add_text(buf,n,line);add_text(buf,n,"\n");line_length=0;text++;continue;}
        while(*text==' ')text++;
        const char *word=text;size_t word_length=0;while(text[word_length]&&text[word_length]!=' '&&text[word_length]!='\r'&&text[word_length]!='\n')word_length++;size_t consumed=word_length;
        if(!word_length)continue;
        if(line_length&&line_length+1+word_length>width){line[line_length]=0;add_text(buf,n,line);add_text(buf,n,"\n");line_length=0;}
        while(word_length>width){if(line_length){line[line_length]=0;add_text(buf,n,line);add_text(buf,n,"\n");line_length=0;}char chunk[64];memcpy(chunk,word,width);chunk[width]=0;add_text(buf,n,chunk);add_text(buf,n,"\n");word+=width;word_length-=width;}
        if(line_length)line[line_length++]=' ';
        memcpy(line+line_length,word,word_length);line_length+=word_length;text+=consumed;
    }
    if(line_length){line[line_length]=0;add_text(buf,n,line);add_text(buf,n,"\n");}
}
static int raw_print(const unsigned char *data,DWORD length){DWORD needed=0,written=0;HANDLE printer=NULL;DOC_INFO_1A doc={"Reka Queue Ticket",NULL,"RAW"};GetDefaultPrinterA(NULL,&needed);if(!needed)return 0;char *name=(char*)malloc(needed);if(!name||!GetDefaultPrinterA(name,&needed))return 0;if(!OpenPrinterA(name,&printer,NULL)){free(name);return 0;}free(name);int ok=0;if(StartDocPrinterA(printer,1,(LPBYTE)&doc)){if(StartPagePrinter(printer)){ok=WritePrinter(printer,(LPVOID)data,length,&written)&&written==length;EndPagePrinter(printer);}EndDocPrinter(printer);}ClosePrinter(printer);return ok;}
static int print_ticket(const char *json){
    char header[320]="REKA QUEUE MANAGEMENT",footer[320]="Mohon menunggu nomor Anda dipanggil.",service[160]="LAYANAN",number[64]="---",created[64]="";
    json_value(json,"ticket_header",header,sizeof(header));
    json_value(json,"ticket_footer",footer,sizeof(footer));
    json_value(json,"service_name",service,sizeof(service));
    json_value(json,"ticket_number",number,sizeof(number));
    json_value(json,"created_at",created,sizeof(created));
    CharUpperBuffA(header,(DWORD)strlen(header));
    CharUpperBuffA(service,(DWORD)strlen(service));
    CharUpperBuffA(number,(DWORD)strlen(number));

    unsigned char b[4096];size_t n=0;
    const unsigned char init[]={0x1b,0x40,0x1b,0x4d,0x00,0x1b,0x61,0x01};
    const unsigned char boldon[]={0x1b,0x45,0x01};
    const unsigned char boldoff[]={0x1b,0x45,0x00};
    const unsigned char triple[]={0x1d,0x21,0x22};
    const unsigned char normal[]={0x1d,0x21,0x00};
    const unsigned char cut[]={0x1d,0x56,0x00};

    add_bytes(b,&n,init,sizeof(init));
    add_bytes(b,&n,boldon,sizeof(boldon));
    add_wrapped_text(b,&n,header,32);
    add_bytes(b,&n,boldoff,sizeof(boldoff));
    add_text(b,&n,"--------------------------------\n");
    add_bytes(b,&n,boldon,sizeof(boldon));
    add_wrapped_text(b,&n,service,32);
    add_bytes(b,&n,boldoff,sizeof(boldoff));
    add_text(b,&n,created);
    add_text(b,&n,"\nNOMOR ANTREAN\n\n");
    add_bytes(b,&n,triple,sizeof(triple));
    add_bytes(b,&n,boldon,sizeof(boldon));
    add_text(b,&n,number);
    add_text(b,&n,"\n");
    add_bytes(b,&n,normal,sizeof(normal));
    add_bytes(b,&n,boldoff,sizeof(boldoff));
    add_text(b,&n,"\n--------------------------------\n");
    add_wrapped_text(b,&n,footer,32);
    add_text(b,&n,"\n\n\n\n\n");
    add_bytes(b,&n,cut,sizeof(cut));
    return raw_print(b,(DWORD)n);
}
static void respond(SOCKET client,const char *status,const char *body){char header[512];int length=(int)strlen(body);int n=snprintf(header,sizeof(header),"HTTP/1.1 %s\r\nAccess-Control-Allow-Origin: *\r\nAccess-Control-Allow-Methods: POST, OPTIONS\r\nAccess-Control-Allow-Headers: Content-Type\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n",status,length);send(client,header,n,0);if(length)send(client,body,length,0);}
static void handle(SOCKET client){char request[REQUEST_MAX+1];int total=0,got,content_length=0;char *headers_end=NULL,*p;while(total<REQUEST_MAX&&(got=recv(client,request+total,REQUEST_MAX-total,0))>0){total+=got;request[total]=0;if(!headers_end&&(headers_end=strstr(request,"\r\n\r\n"))){p=strstr(request,"Content-Length:");if(p)content_length=atoi(p+15);}if(headers_end&&total>=(int)(headers_end+4-request)+content_length)break;}if(total<=0)return;if(!strncmp(request,"OPTIONS ",8)){respond(client,"204 No Content","");return;}if(strncmp(request,"POST /print ",12)){respond(client,"404 Not Found","not found");return;}headers_end=strstr(request,"\r\n\r\n");if(!headers_end){respond(client,"400 Bad Request","invalid request");return;}int ok=print_ticket(headers_end+4);respond(client,ok?"200 OK":"500 Internal Server Error",ok?"printed":"printer error");}
int WINAPI WinMain(HINSTANCE instance,HINSTANCE previous,LPSTR command,int show){(void)instance;(void)previous;(void)command;(void)show;HANDLE mutex=CreateMutexA(NULL,TRUE,"RekaThermalPrintAgent");if(!mutex||GetLastError()==ERROR_ALREADY_EXISTS)return 0;WSADATA data;if(WSAStartup(MAKEWORD(2,2),&data))return 1;SOCKET server=socket(AF_INET,SOCK_STREAM,IPPROTO_TCP);if(server==INVALID_SOCKET)return 1;struct sockaddr_in addr;memset(&addr,0,sizeof(addr));addr.sin_family=AF_INET;addr.sin_addr.s_addr=htonl(INADDR_LOOPBACK);addr.sin_port=htons(PORT);if(bind(server,(struct sockaddr*)&addr,sizeof(addr))==SOCKET_ERROR)return 1;if(listen(server,8)==SOCKET_ERROR)return 1;for(;;){SOCKET client=accept(server,NULL,NULL);if(client!=INVALID_SOCKET){handle(client);closesocket(client);}}}
