<?php
/** Offline address evidence contracts; actual parser/comparator, no database or provider. */
require __DIR__.'/../../app/Core/Contracts/KeywordRankingSourceInterface.php';
require __DIR__.'/../../app/Services/Seo/DataForSeoRankingSource.php';
require __DIR__.'/../../app/Services/GoogleBusiness/NapConsistencyService.php';
$n=0;
function check($ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);$n++;echo 'PASS '.$message.PHP_EOL;}
$full=['addressLines'=>['10 Nile Street'],'locality'=>'Cairo','administrativeArea'=>'Cairo','postalCode'=>'12345','regionCode'=>'EG'];
$state=fn($a,$b)=>NapConsistencyService::compare(['address'=>$a],['address'=>$b])['address']['state'];
check($state($full,['regionCode'=>'EG'])==='unknown','a country alone does not establish a conflicting full business address');
check($state(['regionCode'=>'EG'],$full)==='unknown','an incomplete official address cannot establish an address match or mismatch');
check($state(['regionCode'=>'EG'],['regionCode'=>'EG'])==='unknown','two country-only addresses do not establish a business location match');
check($state($full,['addressLines'=>[''],'locality'=>'','regionCode'=>'EG'])==='unknown','blank structured fields do not turn country metadata into a location');
check($state($full,['addressLines'=>['10 Nile Street'],'regionCode'=>'EG'])==='unknown','an agreeing partial address remains incomplete rather than becoming a mismatch');
check($state($full,$full)==='match','identical complete observed address fields match');
check($state($full,array_replace($full,['postalCode'=>'54321']))==='mismatch','explicit conflicting postal codes retain mismatch evidence');
check($state($full,array_replace($full,['addressLines'=>['99 Other Street']]))==='mismatch','explicit conflicting streets retain mismatch evidence');
check($state($full,'10 Nile Street Cairo')==='unknown','an unstructured partial footer is not asserted to contradict structured fields');
check($state('10 Nile Street','99 Other Street')==='mismatch','two literal observed addresses retain real differences');
$schema='<script type="application/ld+json">{"@type":"TravelAgency","name":"Nile Travel","url":"https://nile.example","address":{"addressCountry":"EG"}}</script>';
$p=NapConsistencyService::extract($schema,'nile.example');
check($state($full,$p['address'])==='unknown','the actual Organization parser preserves insufficient country-only evidence as unknown');
$p=NapConsistencyService::extract($schema.'<footer><address>10 Nile Street, Cairo</address></footer>','nile.example');
check($p['address']==='10 Nile Street, Cairo','a country-only schema does not hide an explicit footer address');
check(NapConsistencyService::compare(['name'=>'Nile Travel','phone'=>'+201001234567','website'=>'https://nile.example','address'=>$full],['name'=>'Different Name','phone'=>'01001234567','website'=>'https://nile.example/','address'=>['regionCode'=>'EG']])['name']['state']==='mismatch','insufficient address evidence does not suppress another observed field conflict');
echo $n.' assertions passed'.PHP_EOL;
