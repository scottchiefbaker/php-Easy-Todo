<?php

class html {
	function output($html) {
		if (empty($this->template)) {
			$this->template = "tpls/xhtml-template.html";
		}

		$content = join("",file($this->template));
		$style   = $this->style ?? "";
		$body    = $html        ?? "";

		if (!empty($this->warning)) {
			$body = "<div class=\"warning\"><b>Warning:</b> $this->warning</div>\n\n" . $body;
		}

		/////////////////////////////////////////////////////////////////////////

		$script     = $this->js ?? [];
		$script_str = "";
		foreach ($script as $s) {
			$script_str .= "<script type=\"text/javascript\" src=\"$s\"></script>\n";
		}
		$content = preg_replace("/{js}/",$script_str,$content);

		/////////////////////////////////////////////////////////////////////////

		$css = $this->css ?? [];
		$css_str = "";
		foreach ($css as $s) {
			$css_str .= "<link rel=\"stylesheet\" type=\"text/css\" media=\"screen\" href=\"$s\" />\n";
		}
		$content = preg_replace("/{css}/",$css_str,$content);

		/////////////////////////////////////////////////////////////////////////

		$content = preg_replace("/{body}/"       ,$body,$content);
		$content = preg_replace("/{body_props}/" ,$this->body_props ?? "",$content);
		$content = preg_replace("/{title}/"      ,$this->title      ?? "",$content);
		$content = preg_replace("/{link}/"       ,$this->link       ?? "",$content);

		if (!empty($this->css_file)) {
			$css_file = $this->css_file;
			$css      = "<link rel=\"stylesheet\" type=\"text/css\" media=\"screen\" href=\"$css_file\" title=\"Default\" />";
			$content  = preg_replace("/{css}/",$css,$content);
		}

		$content = preg_replace("/{\w+}/","",$content);

		return $content;
	}

	function error($html) {
		$body = "<h2 style=\"text-align: center; margin-top: 10em;\">$html</h2>";

		print $this->output($html);
		exit;
	}
}
