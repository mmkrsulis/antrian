<?php
declare(strict_types=1);

namespace App\Services;

final class TicketPdf
{
    public static function render(array $ticket): string
    {
        $width=226.77;$height=212.60;$commands=[];$y=194.0;
        $header=(string)setting('ticket_header',app_name());
        foreach(self::lines($header,28) as $line){$commands[]=self::text($line,12,$y,true,$width);$y-=15;}
        $y-=2;$commands[]=self::text((string)$ticket['service_name'],10,$y,false,$width);$y-=13;
        if(!empty($ticket['sub_service_name'])){$commands[]=self::text((string)$ticket['sub_service_name'],8,$y,false,$width);$y-=12;}
        $commands[]="18 $y m 208 $y l S";$y-=35;
        $commands[]=self::text((string)$ticket['ticket_number'],30,$y,true,$width);$y-=25;
        $commands[]="18 $y m 208 $y l S";$y-=14;
        $commands[]=self::text(date('d/m/Y H:i',strtotime((string)$ticket['created_at'])),9,$y,false,$width);$y-=16;
        foreach(self::lines((string)setting('ticket_footer','Mohon menunggu nomor Anda dipanggil.'),38) as $line){$commands[]=self::text($line,8,$y,false,$width);$y-=10;}
        $stream="0 G 0.8 w\n".implode("\n",$commands)."\n";
        $objects=[
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 226.77 212.60] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            "<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream",
        ];
        $pdf="%PDF-1.4\n";$offsets=[0];
        foreach($objects as $index=>$object){$offsets[]=strlen($pdf);$number=$index+1;$pdf.="{$number} 0 obj\n{$object}\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        foreach(array_slice($offsets,1) as $offset)$pdf.=sprintf('%010d 00000 n ', $offset)."\n";
        return $pdf."trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function lines(string $text,int $width): array
    {
        $result=[];foreach(preg_split('/\R/u',$text)?:[] as $paragraph){foreach(explode("\n",wordwrap(trim($paragraph),$width,"\n",true)) as $line){if($line!=='')$result[]=$line;}}
        return $result;
    }

    private static function text(string $value,float $size,float $y,bool $bold,float $pageWidth): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value)?:$value;
        $encoded=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded);
        $x=max(8,($pageWidth-(strlen($encoded)*$size*.52))/2);
        return sprintf('BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET',$bold?'F2':'F1',$size,$x,$y,$encoded);
    }
}
