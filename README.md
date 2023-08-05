# batch-mv  
批量移动文件工具，基于swoole/swow协程实现并发`mv`，可限制并发数量    

## swoole-mv  
需要[swoole-cli](https://www.swoole.com/download/)  

### 使用  
```
➜  swoole-cli swoole-mv.php /opt/test/source /opt/test/dest "*.img" 2
确认mv /opt/test/source/*.img 到 /opt/test/dest 吗？(yes/no) [no]
yes
2022-12-16 13:43:27 mv /opt/test/source/1.img /opt/test/dest/1.img.tmp && mv /opt/test/dest/1.img.tmp /opt/test/dest/1.img
2022-12-16 13:43:27 1.img moved, time cost: 0 seconds, speed: 200 MiB/s

# 不想确认可以在前面加 echo yes | 
```

## swow-mv  
需要swow扩展，可在 [https://github.com/dixyes/lwmbs/actions](https://github.com/dixyes/lwmbs/actions) 下载静态编译的php

### 使用  
```
➜  php swow-mv.php /opt/test/source /opt/test/dest "*.img" 2
确认mv /opt/test/source/*.img 到 /opt/test/dest 吗？(yes/no) [no]
yes
2022-12-16 15:32:41 mv /opt/test/source/1.img /opt/test/dest1.img.tmp && mv /opt/test/dest1.img.tmp /opt/test/dest1.img
2022-12-16 15:32:41 mv /opt/test/source/2.img /opt/test/dest2.img.tmp && mv /opt/test/dest2.img.tmp /opt/test/dest2.img
2022-12-16 15:32:46 2.img moved, time cost: 5 seconds, speed: 204.8 MiB/s
2022-12-16 15:32:46 mv /opt/test/source/3.img /opt/test/dest3.img.tmp && mv /opt/test/dest3.img.tmp /opt/test/dest3.img
2022-12-16 15:32:47 1.img moved, time cost: 6 seconds, speed: 170.67 MiB/s
2022-12-16 15:32:52 3.img moved, time cost: 6 seconds, speed: 170.67 MiB/s

# 不想确认可以在前面加 echo yes | 
```
