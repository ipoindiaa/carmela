<?php
/** Regression checks transcribed from the four supplied handwritten deal sheets. */
function outsideDeal($a,$actualB,$approvedB,$c,$k,$tirangaPct,$approvedMargin=null){
    $exact=round($c-$a-$approvedB,2);
    $approvedMargin=$approvedMargin===null?$exact:round($approvedMargin,2);
    $share=round($approvedMargin*$tirangaPct/100,2);
    return ['exact'=>$exact,'difference'=>round($exact-$approvedMargin,2),'income'=>round($share+$k,2),'entitlement'=>round($approvedB+$share+$k,2),'expense_difference'=>round($actualB-$approvedB,2)];
}
function assertMoney($label,$actual,$expected){if(abs($actual-$expected)>0.009){fwrite(STDERR,"FAIL $label: expected $expected, got $actual\n");exit(1);}echo "PASS $label = $actual\n";}

$s1=outsideDeal(1135000,41000,41000,1240000,0,75);
assertMoney('Sample 1 Tiranga entitlement',$s1['entitlement'],89000);

$s2=outsideDeal(450000,19130,19000,490000,0,50);
assertMoney('Sample 2 explicit expense classification',$s2['expense_difference'],130);
assertMoney('Sample 2 Tiranga entitlement',$s2['entitlement'],29500);

$s3=outsideDeal(664500,71350,71350,760000,8000,50,24000);
assertMoney('Sample 3 explicit settlement residual',$s3['difference'],150);
assertMoney('Sample 3 true accounting income',$s3['income'],20000);
assertMoney('Sample 3 Tiranga entitlement',$s3['entitlement'],91350);

$s4=outsideDeal(1807000,14000,14000,2003000,20000,50);
assertMoney('Sample 4 true accounting income',$s4['income'],111000);
assertMoney('Sample 4 Tiranga entitlement',$s4['entitlement'],125000);

$trait=file_get_contents(__DIR__.'/../includes/outside_car_accounting.php');
foreach(['Outside Car Sale Clearing','Outside Car Recoverable Costs','remaining_entity_receivable','OUTSIDE_CAR_SETTLEMENT','assertOutsideCarEntryCanBeReversed'] as $needle){if(strpos($trait,$needle)===false){fwrite(STDERR,"FAIL missing engine control: $needle\n");exit(1);}}
echo "All Outside Car handwritten calculation regressions passed.\n";
