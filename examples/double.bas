10 REM Legge gli input passati a "./elohim run" e li raddoppia
20 INPUT "numero? "; A
30 LET B = A * 2
40 SEND "RESULT", A; " * 2 = "; B
50 IF A = 0 THEN END
60 GOTO 20
