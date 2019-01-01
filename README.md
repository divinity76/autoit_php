# autoit_php
AutoIt library for php, exposing AutoIt functions in php userland

as of writing, only 5 functions are added: `MouseMove` and `MouseClick` and `_ScreenCapture_Capture` and `WinWaitActive` and `Send`

# example usage
```php
<?php
require_once("autoit.class.php");
$au = new AutoIt();
$au->MouseMove(10, 10, 5);
$au->MouseClick("left");
echo "waiting up to 5 seconds for notepad window..";
if($au->WinWaitActive("[CLASS:Notepad]","",5)){
    echo "found notepad!\n";
    $au->Send("hello from autoit_php");
}else{
    echo "timed out while waiting for notepad.\n";
}
$imageBinary = $au->_ScreenCapture_Capture();
var_dump(strlen($imageBinary), imagecreatefromstring($imageBinary));
```
