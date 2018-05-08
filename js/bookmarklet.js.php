<?php

header('Content-type: application/x-javascript');

if ($_SERVER["HTTPS"]) { $http = "https://"; }
else { $http = "http://"; }

$post_location = $http . $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']) . "/../";

$html = "
<div style=\"z-index: 255; border: 1px solid gray; background-color: #f7ffff; text-align: center; position: absolute; top: 15px; left: 15px; border-radius: 5px; width: calc(100% - 30px); \" id=\"add_todo\">
    <div style=\"padding: 30px 20px;\">
        <form action=\"$post_location\" method=\"get\">
            <input name=\"todo_desc\" style=\"font-size: 150%; width: 100%; height: 2em; border: 0; outline: 1px solid #e3e3e3; padding: 0px 10px;\" id=\"text_area\" placeholder=\"Pick up dry cleaning\" ></input>
            <input type=\"hidden\" name=\"action\" value=\"add_todo\" />
        </form>
    </div>
</div>";

$html = preg_replace("/\"/","\\\"",$html);
$html = preg_replace("/\n/","",$html);

?>
var eid = document.getElementsByTagName('body');

eid[0].innerHTML = eid[0].innerHTML + "<?php print $html; ?>";

location.href = "#";
document.getElementById('text_area').focus();
