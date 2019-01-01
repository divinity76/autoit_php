<?php
declare (strict_types = 1);
// hello there!
// there is about 120 lines of php<->autoit glue, 
// the first autoit function starts with "public function MouseMove"
class AutoIt
{
	protected $srch;
	protected $srcf;
	protected $au3exe_path;
	function __construct(string $au3exe_path = "autodetect")
	{
		if (empty($au3exe_path) || $au3exe_path === "autodetect") {
			$au3exe_path = $this->_LocateAu3Exe(false);
		}
		$au3exe_path = $this->_cygwinify_filepath($au3exe_path);
		if (!is_executable($au3exe_path)) {
			throw new \InvalidArgumentException("supplied au3exe_path is not executable!");
		}
		$this->au3exe_path = $au3exe_path;
		if (!($this->srch = tmpfile())) {
			throw new \RuntimeException("tmpfile failed!");
		}
		$this->srcf = $this->_uncygwinify_filepath(stream_get_meta_data($this->srch)['uri']);
	}
	public static function _uncygwinify_filepath(string $path) : string
	{
		static $is_cygwin_cache = null;
		if ($is_cygwin_cache === null) {
			$is_cygwin_cache = (false !== stripos(PHP_OS, "cygwin"));
		}
		if ($is_cygwin_cache) {
			return trim(shell_exec("cygpath -aw " . escapeshellarg($path)));
		} else {
			return $path;
		}

	}
	public static function _cygwinify_filepath(string $path) : string
	{
		static $is_cygwin_cache = null;
		if ($is_cygwin_cache === null) {
			$is_cygwin_cache = (false !== stripos(PHP_OS, "cygwin"));
		}
		if ($is_cygwin_cache) {
			return trim(shell_exec("cygpath -a " . escapeshellarg($path)));
			//return "/cygdrive/" . strtr($path, array(':' => '', '\\' => '/'));
		} else {
			return $path;
		}
	}
	public static function _LocateAu3Exe(bool $force_refresh_cache = false) : string
	{
		static $cache = null;
		if (!$force_refresh_cache && $cache !== null) {
			return $cache;
		}
		$paths = [
			'C:\Program Files (x86)\AutoIt3\AutoIt3_x64.exe',
			'C:\Program Files (x86)\AutoIt3\AutoIt3.exe',
			'C:\Program Files\AutoIt3\AutoIt3_x64.exe',
			'C:\Program Files\AutoIt3\AutoIt3.exe'
		];
		foreach ($paths as $path) {
			if (is_executable($path)) {
				$cache = $path;
				return $cache;
			}
		}
		throw new \RuntimeException("unable to find AutoIt3.exe! hardcode it or something.");
	}
	function __destruct()
	{
		fclose($this->srch); // thanks to tmpfile(), it's auto-deleted upon being fclose()'ed.
	}
	public static function quote_string(string $str) : string
	{
		return '"' . str_replace('"', '""', $str) . '"';
	}
	protected static function _my_shell_exec(string $cmd, string &$stdout = null, string &$stderr = null) : int
	{
		$stdout = "";
		$stderr = "";
		// pipes would be faster but using tmpfile simplifies the code..
		$_stdout = tmpfile();
		$_stderr = tmpfile();
		try {
			$descriptorspec = array(
				0 => array("pipe", "rb"),  // stdin
				1 => $_stdout,
				2 => $_stderr
			);
			$proc = proc_open($cmd, $descriptorspec, $pipes);
			fclose($pipes[0]);
			$ret = proc_close($proc);
			// in theory a single stream_get_contents call with correct arguments would suffice,
			// but in practice: https://bugs.php.net/bug.php?id=76268
			rewind($_stderr);
			rewind($_stdout);
			$stdout = stream_get_contents($_stdout);
			$stderr = stream_get_contents($_stderr);
			return $ret;
		}
		finally {
			fclose($_stderr);
			fclose($_stdout);
		}
	}
	public function exec(string $code, string &$stdout = null, string &$stderr = null) : void
	{
		$stdout = "";
		$stderr = "";
		fwrite($this->srch, $code);
		try {
			// use /ErrorStdOut ? maybe someday.
			$cmd = escapeshellarg($this->au3exe_path) . " " . escapeshellarg($this->srcf);
			$ret = $this->_my_shell_exec($cmd, $stdout, $stderr);
			if ($ret !== 0) {
				throw new \RuntimeException("AutoIt failed, returned error code {$ret}");
			}
		}
		finally {
			rewind($this->srch);
			ftruncate($this->srch, 0);
		}
	}
	public function MouseMove(int $x, int $y, int $speed = 10) : void
	{
		$this->exec("MouseMove({$x}, {$y}, {$speed});");
	}
	public function MouseClick(string $button = "left", int $x = null, int $y = null, int $clicks = 1, int $speed = 10) : void
	{
		if ($x === null) {
			$x = "Default";
		}
		if ($y === null) {
			$y = "Default";
		}
		$stdout = "";
		$this->exec("ConsoleWrite(MouseClick (" . $this->quote_string($button) . ",{$x},{$y},{$clicks},{$speed}));", $stdout);
		if (trim($stdout) !== "1") {
			throw new \RuntimeException("the button is not in the list or invalid parameter as x without y.");
		}
	}

	/**
	 * _ScreenCapture_Capture
	 * take a screenshot.
	 * if filename is provided, image stored in filename and returns NULL,
	 * otherwise returns the raw image binary in .png-format.
	 * @return string|null 
	 */
	public function _ScreenCapture_Capture(string $sFileName = null, int $iLeft = 0, int $iTop = 0, int $iRight = -1, int $iBottom = -1, bool $bCursor = true)
	{
		$myfh = null;
		if (empty($sFileName)) {
			$myfh = tmpfile();
			$sFileName = stream_get_meta_data($myfh)['uri'] . ".png";
		} elseif (empty(pathinfo($sFileName, PATHINFO_EXTENSION))) {
			throw new \InvalidArgumentException("sFileName must either be NULL -or- have an extension (.jpg,.png,.bmp, etc) (AutoIt requirement.)");
		}
		$code =
			"#include <ScreenCapture.au3>\r\n" .
			"_ScreenCapture_Capture ( " . $this->quote_string($this->_uncygwinify_filepath($sFileName)) . ", {$iLeft}, {$iTop},{$iRight},{$iBottom}," . ($bCursor ? "True" : "False") . " );\r\n" .
			"ConsoleWrite(@error);";
		$this->exec($code, $stdout);
		$ret = null;
		if (!empty($myfh)) {
			$ret = file_get_contents($sFileName);
			fclose($myfh);
			unlink($sFileName);
		}
		if (trim($stdout) !== "0") {
			throw new \RuntimeException("AutoIt's @error was not 0 at exit, it was: " . var_export($stdout, true));
		}
		return $ret;
	}
	/**
	 * Pauses execution of the script until the requested window is active.
	 * returns bool(true) on success, and bool(false) on timeout.
	 * https://www.autoitscript.com/autoit3/docs/functions/WinWaitActive.htm
	 * @param string $title
	 * @param string $text
	 * @param integer $timeout
	 * @return boolean
	 */
	public function WinWaitActive(string $title, string $text = "", int $timeout = 0) : bool
	{
		$this->exec("ConsoleWrite(WinWaitActive (" . $this->quote_string($title) . ", " . $this->quote_string($text) . ",{$timeout}));", $stdout);
		return (trim($stdout) !== "0");
	}
	/**
	 * Sends simulated keystrokes to the active window.
	 * https://www.autoitscript.com/autoit3/docs/functions/Send.htm
	 *
	 * @param string $keys
	 * @param boolean $raw
	 * @param integer $SendKeyDelay
	 * @param integer $SendKeyDownDelay
	 * @return void
	 */
	public function Send(string $keys, bool $raw = false, int $SendKeyDelay = 5, int $SendKeyDownDelay = 5) : void
	{
		$code =
			"AutoItSetOption ( 'SendKeyDelay', {$SendKeyDelay});\r\n" .
			"AutoItSetOption ( 'SendKeyDownDelay', {$SendKeyDownDelay});\r\n" .
			"Send(" . $this->quote_string($keys) . "," . ((int)$raw) . ");";
		$this->exec($code);
	}
};
