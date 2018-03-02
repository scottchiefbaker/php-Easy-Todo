var eid = document.getElementsByTagName('body');

eid[0].innerHTML = eid[0].innerHTML + "<div style=\"z-index: 255; border: 1px solid black; background-color: white; text-align: center; position: absolute; top: 5px; left: 5px; width: 98%; \" id=\"add_todo\"> <div style=\"padding: 15px; \"><a onclick=\"javascript: var eid = document.getElementById('add_todo'); eid.innerHTML = ''; eid.style.display = 'none'; return false; \" href=\"/\">Hide</a> <form action=\"http://10.1.1.1/todo/index.php\" method=\"get\"> <textarea name=\"todo_desc\" style=\"width: 90%; height: 5em; \" id=\"text_area\"></textarea> <input type=\"hidden\" name=\"action\" value=\"add_todo\" /> <br /> <input type=\"submit\" value=\"Add item\" /> </form> </div> </div>";

location.href = "#";
document.getElementById('text_area').focus();
