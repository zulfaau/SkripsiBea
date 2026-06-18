<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Http\Request;

$controller = $app->make(App\Http\Controllers\ChatbotController::class);
$store = $app['session']->driver();
$store->start();
$r = new ReflectionClass($controller);
$norm = $r->getMethod('normalizeText'); $norm->setAccessible(true);

function ask($app,$controller,$store,$q,$reset){
  if($reset) $store->flush();
  $req = Request::create('/chatbot/ask','POST',['message'=>$q,'rag_enabled'=>true]);
  $req->setLaravelSession($store); $app->instance('request',$req);
  for($t=0;$t<4;$t++){
    try { $resp=$controller->ask($req); $d=json_decode($resp->getContent(),true);
      if(isset($d['answer'])) return $d['answer'];
      $m=$d['message']??''; if(stripos($m,'gangguan')===false && stripos($m,'valid')===false) return '[non] '.$m;
    } catch(\Throwable $e){ return '[ERR] '.$e->getMessage(); }
    usleep(1500000);
  }
  return '[GAGAL]';
}

echo "=== Cek normalisasi kata paginasi (harus UTUH) ===\n";
foreach(['selanjutnya','berikutnya','lainnya','ada lagi nggak'] as $w){
  printf("  %-16s -> \"%s\"\n",$w,$norm->invoke($controller,$w)['text']);
}

$phrases = ['yang lain','Tampilkan yang lain','selanjutnya','berikutnya','lainnya','ada lagi nggak?','masih ada?','next'];
echo "\n=== Uji paginasi (seed: \"beasiswa luar negeri\" tiap kali) ===\n";
$ok=0;
foreach($phrases as $p){
  ask($app,$controller,$store,'beasiswa luar negeri',true); // seed page 1
  $a = ask($app,$controller,$store,$p,false);               // minta halaman berikut
  $first = trim(strtok($a,"\n"));
  $isNext = (stripos($a,'beasiswa selanjutnya')!==false);
  if($isNext)$ok++;
  printf("  %-20s -> %s %s\n",$p,$isNext?'NEXT-PAGE OK':'??',substr($first,0,55));
}
echo "\nHASIL PAGINASI: $ok/".count($phrases)." frasa memicu halaman berikutnya\n";
