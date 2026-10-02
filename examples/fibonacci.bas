10 REM Calcolo puro: si spezza in molti quantum su nodi diversi
20 A = 0 : B = 1
30 FOR N = 1 TO 30
40 PRINT "F("; N; ") = "; A
50 T = A + B : A = B : B = T
60 NEXT N
70 END
