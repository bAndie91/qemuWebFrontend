<?php

define('S_IFMT',  0x00170000);
define('S_IFSOCK', 0x0140000);
define('S_IFLNK',  0x0120000);
define('S_IFREG',  0x0100000);
define('S_IFBLK',  0x0060000);
define('S_IFDIR',  0x0040000);
define('S_IFCHR',  0x0020000);
define('S_IFIFO',  0x0010000);
define('S_ISUID',  0x0004000);
define('S_ISGID',  0x0002000);
define('S_ISVTX',  0x0001000);

define('S_IRWXU',    0x00700);
define('S_IRUSR',    0x00400);
define('S_IWUSR',    0x00200);
define('S_IXUSR',    0x00100);

define('S_IRWXG',    0x00070);
define('S_IRGRP',    0x00040);
define('S_IWGRP',    0x00020);
define('S_IXGRP',    0x00010);

define('S_IRWXO',    0x00007);
define('S_IROTH',    0x00004);
define('S_IWOTH',    0x00002);
define('S_IXOTH',    0x00001);


define('VNC_DISPLAY_MAX', 1024);
define('VNC_PORT_BASE', 5900);
