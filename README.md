# ecr-17-pos-driver

Driver PHP per il controllo di terminali POS tradizionali Nexi (protocollo ECR17). Comandi di pagamento, stato, totali, chiusura sessione e ristampa ticket via LAN.

## Requisiti

- PHP 5.4+ (estensione sockets)
- Terminale POS Nexi connesso in LAN (protocollo ECR17)

## Dispositivo utilizzato per le prove

Le prove sono state effettuate con:

- **Modello:** Ingenico AXIUM DX8000  
- **Acquirer:** Banca di Piacenza  
- **Processor:** Worldline  

## Configurazione

1. Copia `config.example.php` in `config.php`.
2. Modifica `POS_HOST` e `POS_PORT` con l’indirizzo IP e la porta del tuo terminale.

Se `config.php` non esiste, vengono usate le variabili d’ambiente `POS_HOST` e `POS_PORT` oppure i valori predefiniti (192.168.1.15:8000).

## File

| File | Descrizione |
|------|-------------|
| `ingenico.php` | Invio comando di **pagamento** al POS (parametri GET: `amount`, `tid`, `crid`, `timeout`, `debug`). |
| `test_file.php` | Script di test multi-comando: pagamento, stato terminale, totali, chiusura sessione, ristampa ticket. |
| `config.example.php` | Esempio di configurazione (copia in `config.php`). |
| `docs/Nexi_ECR17_Protocol.txt` | Riferimento del protocollo ECR17. |

## Utilizzo rapido

**Pagamento (es. 10,50 €):**
```
ingenico.php?amount=10.50
```

**Stato terminale:**
```
test_file.php?status=1
```

**Totali:**
```
test_file.php?totals=1
```

**Chiusura sessione:**
```
test_file.php?close=1
```

Vedi i commenti in test_file.php per tutti i parametri (`tid`, `crid`, `timeout`, `reprint`, ecc.).

## Licenza

Utilizzo libero. Riferimento: [Nexi Traditional POS – Communication protocol]([https://developer.nexigroup.com/traditionalpos/](https://developer.nexigroup.com/traditionalpos/en-EU/docs/).
