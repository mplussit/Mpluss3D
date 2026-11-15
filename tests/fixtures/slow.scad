// Bevisst treg modell – brukes for å teste tidsavbrudd.
// 8 milliarder iterasjoner som ikke lagrer noe (lite minnebruk).
n = len([for (i = [0 : 1999], j = [0 : 1999], k = [0 : 1999]) if (i < 0) 1]);
cube(n + 1);
