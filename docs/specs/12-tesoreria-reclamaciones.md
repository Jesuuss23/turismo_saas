# Tesorería (caja y bancos) y Libro de Reclamaciones virtual

Prototipo: `proto/assets/js/modules/tesoreria.js` · Rutas `#/finanzas/tesoreria/*`, `#/crm/reclamaciones/*`, `#/libro-reclamaciones`.

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Submódulo | Propósito | Procesos relacionados |
|---|---|---|
| Tesorería | Posición de caja y bancos del tenant: cuentas bancarias PEN/USD, cajas chicas por sede, pasarela de pagos como cuenta de tránsito. Refleja automáticamente los cobros (PROC-042/043) y los pagos a proveedores (PROC-046). Registra transferencias, gastos de caja chica con comprobante, apertura y cierre diario con arqueo, reposición del fondo fijo, flujo proyectado 30/60/90 y diferencia cambiaria. | PROC-042 Pago, PROC-043 Reversión, PROC-046 Pago proveedor, PROC-049 Reconciliación (enlace a `bankLines`), PROC-059 Notificaciones, PROC-061 Exportación, PROC-063 Auditoría |
| Libro de Reclamaciones | Hoja de reclamación virtual (obligatoria en Perú para proveedores de servicios), con correlativo por sede, plazo de respuesta en días hábiles, respuesta enviada al consumidor, constancia y reportes. Las hojas viven en la misma colección de casos de postventa del CRM (`DB.claims` con `book: true`). | PROC-009 Postventa, PROC-051 Portal cliente, PROC-052 Portal pasajero, PROC-055 CMS/sitio web, PROC-059, PROC-063 |

Marco normativo de referencia: Ley N.° 29571 (Código de Protección y Defensa del Consumidor) y el Reglamento del Libro de Reclamaciones (D.S. N.° 011-2011-PCM y modificatorias). El plazo de respuesta (15 días hábiles) está implementado como **parámetro configurable**. **Validar la normativa vigente con asesoría legal** antes de salir a producción: plazos, campos obligatorios de la hoja, conservación, aviso en el establecimiento y reportes a INDECOPI.

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/finanzas/tesoreria` (`/resumen`) | `/finanzas/tesoreria` | KPIs (saldo consolidado, bancos PEN/USD, cajas + pasarela, saldo proyectado a 30 d), tabla de cuentas, composición del saldo, alertas TES-BR, saldo consolidado de 28 días y últimos movimientos | Tenant Admin, Manager, Finanzas, Contabilidad (`finance.treasury`) |
| `#/finanzas/tesoreria/movimientos` | `/finanzas/tesoreria/movimientos` | Libro de movimientos con filtros (cuenta, tipo, sentido, estado), origen enlazado (PAG/CXP/TRF/ARQ), detalle en drawer, anulación de movimientos manuales | idem |
| `#/finanzas/tesoreria/cajas` | `/finanzas/tesoreria/cajas` | Tarjeta por caja chica (saldo/fondo, sesión del día, gastos por rendir) con acciones Aperturar, Gasto, Arqueo, Cerrar caja y Reposición; tablas de arqueos, reposiciones y sesiones | idem (las acciones según permisos, ver §7) |
| `#/finanzas/tesoreria/flujo` | `/finanzas/tesoreria/flujo` | Flujo proyectado por horizontes 0–30/31–60/61–90, gráfico semanal de 13 semanas, saldo proyectado y detalle (CxC, CxP, comisiones, egresos fijos) | idem |
| `#/finanzas/tesoreria/cambio` | `/finanzas/tesoreria/cambio` | Revaluación de saldos USD (no realizada) con TC de cierre, diferencia realizada en salidas USD e historial | idem; registra solo `finance.invoicing` |
| `#/finanzas/tesoreria/cuenta/{CTA}` | `/finanzas/tesoreria/cuentas/[code]` | Libro de la cuenta con saldo acumulado; acciones según tipo (movimiento/transferencia, gasto/arqueo/cierre, liquidar pasarela) | idem |
| `#/crm/reclamaciones` (`/hojas`) | `/crm/reclamaciones` | KPIs (del mes, abiertas, por vencer, vencidas, tiempo medio), listado con filtros de tipo, sede, estado y plazo; registro presencial; job de alertas | Tenant Admin, Manager (`crm.claims.manage`) |
| `#/crm/reclamaciones/reportes` | `/crm/reclamaciones/reportes` | Por tipo, por sede, cumplimiento del plazo, por mes (6 meses), vencidas y relación anual para INDECOPI | idem |
| `#/crm/reclamaciones/configuracion` | `/crm/reclamaciones/configuracion` | Plazo en días hábiles, días de alerta, años de conservación, feriados y correlativos por sede | ver: idem · editar: `admin.settings.manage` |
| `#/crm/reclamaciones/{REC}` | `/crm/reclamaciones/[code]` | Hoja: flujo de estados, contador de plazo, consumidor (con apoderado si es menor), bien contratado, vinculación con cliente y reserva, detalle y pedido, respuesta, historial, auditoría y constancia | idem |
| `#/libro-reclamaciones` | `app/(portal-cliente)/libro-reclamaciones` y `app/(portal-pasajero)/libro-reclamaciones`; público: `/sitio/libro-reclamaciones` | Formulario de la hoja virtual. Cliente/pasajero: datos precargados, "Mis hojas" con estado, respuesta y constancia. Interno: vista previa sin registrar | Cliente, Pasajero (rutas del portal); interno con `crm.claims.manage` como vista previa; visitantes sin sesión desde el sitio web (sitio.js) |

## 3. Modelo de datos PostgreSQL

Todas las tablas llevan `tenant_id uuid not null` (FK `tenants`), RLS `tenant_isolation`, `created_by/updated_by uuid`, `created_at/updated_at timestamptz`. Índices por FK.

### 3.1 `fin_treasury_accounts` (DB.treasuryAccounts)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK (UUID v7) |
| code | varchar(20) | no | `CTA-01`; `UNIQUE (tenant_id, code)` |
| kind | varchar(20) | no | `CHECK (kind IN ('bank','petty_cash','gateway'))` |
| bank | varchar(40) | sí | BCP, Interbank, BBVA |
| name | varchar(120) | no | |
| account_number | varchar(40) | sí | `UNIQUE (tenant_id, bank, account_number)` |
| cci | varchar(25) | sí | Código de cuenta interbancario |
| currency | char(3) | no | `CHECK (currency IN ('PEN','USD'))` |
| sede_id | uuid | no | FK `iam_sedes` |
| fund_amount | numeric(14,2) | sí | Fondo fijo; obligatorio si `kind='petty_cash'` (CHECK) |
| custodian_id | uuid | sí | FK `iam_users`; obligatorio en caja chica |
| settle_to_account_id | uuid | sí | FK self; obligatorio en pasarela |
| opening_balance | numeric(14,2) | no | Saldo inicial de puesta en marcha |
| opening_rate | numeric(12,6) | sí | TC contable inicial (USD) |
| opening_date | date | no | |
| status | varchar(20) | no | `CHECK (status IN ('active','inactive'))` |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | |

El saldo no se persiste como verdad: se deriva de `opening_balance + Σ movimientos`. Opcionalmente, la vista materializada `fin_treasury_balances` (refresh por evento) guarda el saldo para los listados.

### 3.2 `fin_treasury_movements` (DB.treasuryMovements)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(20) | no | `MOV-000123`; `UNIQUE (tenant_id, code)` |
| account_id | uuid | no | FK `fin_treasury_accounts`; índice `(tenant_id, account_id, movement_date)` |
| movement_date | date | no | `CHECK (movement_date <= current_date)` en FormRequest |
| direction | char(3) | no | `CHECK (direction IN ('in','out'))` |
| type | varchar(30) | no | `CHECK (type IN ('collection','collection_reversal','supplier_payment','transfer','petty_expense','replenishment','gateway_settlement','gateway_fee','count_adjustment','other_income','other_expense'))` |
| amount | numeric(14,2) | no | `CHECK (amount > 0)`, en moneda de la cuenta |
| currency | char(3) | no | igual a la moneda de la cuenta (validado en Service) |
| fx_rate | numeric(12,6) | sí | TC de la operación (cuentas USD) |
| orig_amount / orig_currency / orig_fx_rate | numeric(14,2) / char(3) / numeric(12,6) | sí | Monto original si difiere la moneda (p. ej. Yape USD→BCP PEN) |
| source_type | varchar(30) | no | `payment`, `payment_reversal`, `payout`, `payable`, `transfer`, `expense`, `replenishment`, `gateway`, `cash_count`, `manual` |
| source_id | uuid | sí | FK polimórfica (pago, CxP, arqueo) |
| source_key | varchar(60) | sí | Clave idempotente de sincronización `PAG:{code}`, `REV:{code}`, `PPR:{code}`, `CXP:{code}`; `UNIQUE (tenant_id, source_key)` |
| transfer_id | uuid | sí | FK `fin_treasury_transfers`; agrupa las partidas de una transferencia o liquidación |
| replenishment_id | uuid | sí | FK `fin_cash_replenishments` (gastos rendidos) |
| reservation_id | uuid | sí | FK `booking_reservations` (costo imputable del gasto) |
| category | varchar(60) | sí | |
| description | varchar(255) | no | |
| reference | varchar(80) | sí | N° de operación o comprobante |
| counterparty | varchar(160) | sí | |
| sede_id | uuid | sí | FK |
| deposited_transfer_id | uuid | sí | Cobro en efectivo ya depositado |
| status | varchar(20) | no | `CHECK (status IN ('posted','voided'))` |
| void_reason, voided_by, voided_at | text, uuid, timestamptz | sí | Obligatorios si `voided` (CHECK) |

Índices: `(tenant_id, movement_date desc)`, `(tenant_id, type)`, `(tenant_id, status)`, `(tenant_id, replenishment_id)`.

### 3.3 `fin_expense_receipts` (gasto de caja chica, en el prototipo `movement.receipt`)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| movement_id | uuid | no | FK único `fin_treasury_movements` |
| receipt_type | varchar(30) | no | `CHECK IN ('invoice','sales_receipt','ticket','fee_receipt','mobility_sheet','sworn_statement')` |
| series_number | varchar(30) | sí | Obligatorio salvo planilla de movilidad y declaración jurada |
| issuer_ruc | char(11) | sí | Obligatorio en factura y recibo por honorarios; `CHECK (issuer_ruc ~ '^\d{11}$')` |
| supplier_name | varchar(160) | no | |
| file_path | varchar(255) | sí | S3 `tenants/{tenant}/treasury/{uuid}.pdf` |

Índice `UNIQUE (tenant_id, issuer_ruc, series_number)` parcial (`WHERE issuer_ruc IS NOT NULL`) para evitar la doble rendición de un comprobante.

### 3.4 `fin_treasury_transfers`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(20) | no | `TRF-0034` único por tenant |
| from_account_id / to_account_id | uuid | no | FK; `CHECK (from_account_id <> to_account_id)` |
| amount_from / amount_to | numeric(14,2) | no | |
| deal_rate | numeric(12,6) | sí | TC pactado entre monedas |
| kind | varchar(20) | no | `transfer`, `replenishment`, `gateway_settlement`, `cash_deposit` |
| transfer_date | date | no | |
| reference | varchar(80) | no | |

### 3.5 `fin_cash_sessions` (DB.cashSessions)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; `code` `CJS-0122` |
| account_id | uuid | no | FK (solo `petty_cash`) |
| session_date | date | no | `UNIQUE (tenant_id, account_id, session_date)` |
| opened_at / opened_by | timestamptz / uuid | no | |
| opening_amount | numeric(14,2) | no | Debe igualar el saldo del último cierre |
| closed_at / closed_by | timestamptz / uuid | sí | |
| closing_count_id | uuid | sí | FK `fin_cash_counts` (obligatorio si `closed`) |
| status | varchar(10) | no | `CHECK IN ('open','closed')`; índice parcial único `(tenant_id, account_id) WHERE status='open'` |

### 3.6 `fin_cash_counts` (DB.cashCounts) — acta de arqueo
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; `code` `ARQ-0044` |
| account_id | uuid | no | FK |
| session_id | uuid | sí | FK (cierre) |
| kind | varchar(10) | no | `CHECK IN ('surprise','closing')` |
| counted_at | timestamptz | no | |
| denominations | jsonb | no | `{"200":2,"100":5,…,"0.1":3}`; validado contra la lista de denominaciones PEN |
| counted_amount / expected_amount / difference | numeric(14,2) | no | `difference = counted − expected` (CHECK) |
| result | varchar(10) | no | `CHECK IN ('balanced','over','short')` |
| note | text | sí | Obligatoria si `difference <> 0` |
| signed_by / signed_at | uuid / timestamptz | no | Firma electrónica (usuario + sello de tiempo + IP en `audit_logs`) |
| witness_id | uuid | sí | FK usuario |
| status | varchar(25) | no | `CHECK IN ('signed','pending_approval','approved','validated')` |
| approved_by / approved_at / approve_note | uuid / timestamptz / text | sí | `CHECK (approved_by <> signed_by)` |
| validated_by / validated_at | uuid / timestamptz | sí | Visto bueno contable |
| adjustment_movement_id | uuid | sí | FK al movimiento `count_adjustment` |

### 3.7 `fin_cash_replenishments` (DB.cashReplenishments)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; `code` `REP-0009` |
| account_id | uuid | no | FK caja chica |
| amount | numeric(14,2) | no | = Σ gastos incluidos (Service) |
| status | varchar(15) | no | `CHECK IN ('requested','approved','paid','rejected')`; índice parcial único `(tenant_id, account_id) WHERE status IN ('requested','approved')` |
| requested_by/at, approved_by/at, paid_by/at | uuid/timestamptz | sí | `CHECK (approved_by <> requested_by)` |
| paid_from_account_id / transfer_id | uuid | sí | FK |
| reject_reason, note | text | sí | |

Los gastos incluidos se relacionan por `fin_treasury_movements.replenishment_id`, y el historial de estados por `fin_cash_replenishment_events`.

### 3.8 `fin_fx_revaluations` (DB.fxRevaluations)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; `code` `DC-0024` |
| account_id | uuid | no | FK (solo USD) |
| period | char(7) | no | `2026-09`; `UNIQUE (tenant_id, account_id, period)` |
| revaluation_date | date | no | |
| balance_usd | numeric(14,2) | no | |
| book_rate / close_rate | numeric(12,6) | no | |
| diff_pen | numeric(14,2) | no | + ganancia (776) / − pérdida (676) |

### 3.9 `fin_treasury_settings` (DB.treasurySettings, 1 fila por tenant)
`petty_tolerance numeric(14,2)` (10.00), `max_petty_expense` (500.00), `sworn_statement_max` (150.00), `replenish_threshold_pct smallint` (40), `gateway_fee_pct numeric(5,2)` (4.00), `cash_deposit_hours smallint` (24) y `recurring_outflows jsonb` (`[{concept, amount, day}]` para el flujo proyectado).

### 3.10 Libro de Reclamaciones: `crm_claims` (DB.claims, extendida)
La tabla de casos de postventa del CRM se extiende de forma compatible. Una hoja del libro es un caso con `is_book = true`. Los casos existentes (NPS, sugerencias) no cambian.

| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK; `code` `REC-0015` (secuencia del CRM, compartida) |
| is_book | boolean | no | default false |
| book_number | varchar(25) | sí | `LR-{SEDE}-{AAAA}-{000001}`; `UNIQUE (tenant_id, book_number)`; obligatorio si `is_book` (CHECK) |
| sede_id | uuid | sí | FK; obligatorio si `is_book` |
| channel | varchar(30) | sí | `web`, `customer_portal`, `passenger_portal`, `in_person` |
| type | varchar(20) | no | CRM: `Reclamo, Queja, Sugerencia, Felicitación`; libro: solo `Reclamo`/`Queja` (CHECK `NOT is_book OR type IN (...)`) |
| client_id / reservation_id | uuid | sí | FK (vinculación) |
| status | varchar(20) | no | Vocabulario CRM `open, in_progress, resolved, closed` (lo usa Postventa) |
| book_status | varchar(20) | sí | `CHECK IN ('received','in_review','answered','closed')`; se mapea a `status` en el Service (recibido→abierta, evaluación→en_proceso, respondido→resuelta, cerrado→cerrada) |
| consumer | jsonb | sí | `{name, doc_type, doc_number, address, email, phone, is_minor, guardian:{name, doc_type, doc_number, relation}}`, cifrado a nivel de aplicación (Laravel `encrypted:array`) por tratarse de datos personales |
| good | jsonb | sí | `{kind:'Servicio'|'Producto', description, amount, currency}` |
| detail / request | text | sí | Detalle y pedido del consumidor |
| received_at | timestamptz | no | = `created_at`; inmutable |
| due_date | date | sí | Calculada al recibir con `response_days` hábiles; inmutable |
| response_days | smallint | sí | Snapshot del parámetro |
| response | jsonb | sí | `{decision, text, actions, answered_at, answered_by, sent_at, sent_to, notification_id}` |
| closed_at / close_note | timestamptz / text | sí | |
| owner_id | uuid | no | Responsable (Tenant Admin / Manager) |
| priority, subject, resolution, survey_id | — | — | Campos existentes del CRM (Reclamo→alta, Queja→media) |
| ack_sent_at | timestamptz | sí | Envío de la constancia |
| submitted_by | uuid | sí | Usuario del portal o personal; null = anónimo web |

Índices: `(tenant_id, is_book, book_status)`, `(tenant_id, due_date) WHERE is_book AND book_status IN ('received','in_review')`, `(tenant_id, sede_id)` y `(tenant_id, client_id)`. Historial en `crm_claim_events (claim_id, ts, user_id, message)`.

### 3.11 `crm_claim_book_settings` y `sys_holidays`
- `crm_claim_book_settings`: `response_days smallint default 15 CHECK (response_days BETWEEN 1 AND 30)`, `alert_days smallint default 3`, `retention_years smallint default 2 CHECK (>=2)`.
- Correlativos en `sys_sequences` con clave `claim_book:{sede_id}:{year}` (`SELECT … FOR UPDATE`, sin saltos).
- `sys_holidays (tenant_id nullable, date, name)`: feriados nacionales (tenant_id null) más los locales del tenant.

## 4. Máquinas de estado

**Sesión de caja:** `open → closed`. La abre Finanzas o el Tenant Admin (`POST …/sessions`) y la cierra el acta de arqueo de tipo `closing`. No puede haber dos sesiones abiertas por caja.

**Arqueo:** `signed` → `validated` (Contabilidad). Si `|diferencia| > tolerancia`: `pending_approval → approved` (Manager o Finanzas distinto del firmante) `→ validated`.
Efectos: una diferencia dentro de la tolerancia genera el ajuste `count_adjustment` al firmar. Una diferencia mayor genera el ajuste recién al aprobarse. Si es de cierre, cierra la sesión.

**Reposición:** `requested → approved → paid`, o `requested → rejected`.
- `requested`: Finanzas o Tenant Admin. Marca los gastos pendientes con `replenishment_id`.
- `approved` / `rejected`: Manager, Finanzas o Tenant Admin, siempre distinto del solicitante. El rechazo libera los gastos.
- `paid`: Finanzas o Tenant Admin. Crea la transferencia `replenishment` banco→caja. Si se anula esa transferencia, la reposición vuelve a `approved`.

**Movimiento:** `posted → voided`. Solo se anulan los movimientos manuales, las transferencias (todas sus partidas), los gastos no rendidos y las liquidaciones. Los movimientos sincronizados se corrigen en el documento de origen.

**Revaluación:** registro único por cuenta y periodo; no se modifica.

**Hoja de reclamación:** `received → in_review → answered → closed`, además de `received → answered` directo.
| Transición | Quién | Efectos |
|---|---|---|
| alta (received) | Consumidor (portal/web) o personal (presencial) | Correlativo por sede, `due_date`, constancia por email, notificación al responsable, auditoría |
| → in_review | `crm.claims.manage` | Asignación del responsable y notificación |
| → answered | `crm.claims.manage` | Registra decisión, respuesta y acciones; envía un email al consumidor (o al apoderado); marca "fuera de plazo" si `sent_at > due_date` |
| reenviar | `crm.claims.manage` | Nuevo envío registrado |
| → closed | `crm.claims.manage` | Motivo de cierre; sincroniza `resolution` y `status=closed` para Postventa |

Estado de plazo (derivado): `en_plazo`, `por_vencer` (≤ `alert_days` hábiles), `vencido`, `respondido_en_plazo` y `respondido_fuera_de_plazo`.

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| TES-BR-01 | No se registran movimientos con fecha futura | FormRequest |
| TES-BR-02 | Bancos y cajas no operan en sobregiro; un egreso o transferencia no puede dejar saldo negativo, tampoco al anular transferencias | Service (lock `Cache::lock("treasury:account:{id}")` + `SELECT … FOR UPDATE`) |
| TES-BR-03 | Los movimientos sincronizados (cobros, reversiones, pagos a proveedores) no se editan ni anulan en tesorería; se corrigen en el origen (PROC-043/046) | Policy + Service |
| TES-BR-04 | Sincronización idempotente: un pago confirmado o aplicado genera un solo ingreso (`source_key`); la reversión genera un egreso; cada payout de CxP, un egreso. Crédito B2B no genera movimiento | Listener + UNIQUE `source_key` |
| TES-BR-05 | No se registran gastos ni ingresos de caja con la caja sin aperturar ese día | Service |
| TES-BR-06 | No se apertura una caja si la sesión anterior sigue abierta | Service + índice parcial |
| TES-BR-07 | El conteo de apertura debe igualar el saldo del último cierre; si difiere, primero se hace un arqueo | Service |
| TES-BR-08 | Alerta cuando el saldo de una caja baja del umbral (%) del fondo fijo | Listener `PettyCashBelowThreshold` |
| TES-BR-09 | Los cobros en efectivo se depositan en el banco dentro de N horas (alerta) | Job `treasury:cash-deposit-alerts` |
| TES-BR-10 | Un gasto incluido en una reposición no se anula | Service |
| TES-BR-11 | Tope por gasto de caja chica (S/ 500); por encima se paga por CxP | FormRequest |
| TES-BR-12 | Todo gasto lleva comprobante: serie y número (salvo planilla de movilidad o declaración jurada), RUC de 11 dígitos en factura y RxH, y declaración jurada hasta S/ 150 | FormRequest + UNIQUE comprobante |
| TES-BR-13 | Una diferencia de arqueo mayor a la tolerancia requiere aprobación de gerencia; toda diferencia requiere justificación | Service |
| TES-BR-14 | Segregación de funciones: quien firma un arqueo o solicita una reposición no la aprueba | Policy + CHECK |
| TES-BR-15 | Una sola reposición en curso por caja | Índice parcial |
| TES-BR-16 | Una revaluación por cuenta USD y periodo | UNIQUE |
| TES-BR-17 | Moneda y TC conservados en cada movimiento; conversiones entre monedas guardan el original (Regla transversal 15) | Service |
| TES-BR-18 | Valorización USD por costo promedio ponderado: entradas al TC de la operación y salidas al promedio. La diferencia realizada es monto × (TC operación − promedio); la no realizada es saldo × TC cierre − valor contable | Service `FxValuationService` |
| LR-BR-01 | Correlativo por sede y año, sin saltos ni reutilización | `sys_sequences` FOR UPDATE |
| LR-BR-02 | Plazo = `response_days` días hábiles contados desde el día siguiente a la recepción, excluyendo sábados, domingos y `sys_holidays`; se fija al recibir la hoja | Service `BusinessDayCalculator` |
| LR-BR-03 | Obligatorios: sede, nombre, documento (DNI de 8 dígitos), domicilio, email válido, descripción del bien, tipo (Reclamo o Queja), detalle (≥ 20 caracteres), pedido y aceptación de notificación por email | FormRequest (+ Zod en Next.js) |
| LR-BR-04 | Consumidor menor de edad: se exigen nombre y documento del padre, madre o apoderado | FormRequest |
| LR-BR-05 | Constancia al email del consumidor al registrar (PDF por job) | Listener `ClaimBookEntryReceived` |
| LR-BR-06 | La respuesta es motivada (≥ 40 caracteres), incluye las acciones adoptadas y se envía al email consignado; queda con fecha de envío y referencia de la notificación | FormRequest + Service |
| LR-BR-07 | Solo se cierra una hoja respondida | Service (máquina de estados) |
| LR-BR-08 | El detalle, el pedido, los datos del consumidor y `received_at` son inmutables; sin borrado físico; conservación mínima de 2 años | Policy + SoftDeletes deshabilitado para `is_book` |
| LR-BR-09 | Un consumidor externo solo ve sus hojas (`client_id` propio o su email) | Policy + scope |
| LR-BR-10 | Cambiar el plazo solo afecta a las hojas nuevas | Service |

## 6. API REST

| Método | Endpoint | Permiso | Descripción | Idempotente / lock |
|---|---|---|---|---|
| GET | `/api/v1/treasury/accounts` | finance.treasury | Cuentas con saldo derivado | — |
| GET | `/api/v1/treasury/accounts/{id}/movements` | finance.treasury | Libro con saldo acumulado (cursor) | — |
| POST | `/api/v1/treasury/accounts` · PATCH `/{id}` | admin.settings.manage | Alta/edición de cuentas | lock_version |
| GET | `/api/v1/treasury/movements` | finance.treasury | `filter[account]`, `filter[type]`, `filter[direction]`, `filter[status]`, rango de fechas | — |
| POST | `/api/v1/treasury/movements` | finance.payments.register | Otro ingreso/egreso manual | Idempotency-Key, lock de cuenta |
| POST | `/api/v1/treasury/movements/{id}/void` | finance.payments.register | Anular (motivo) | lock de cuenta |
| POST | `/api/v1/treasury/transfers` | finance.payments.register | Transferencia o depósito de efectivo | Idempotency-Key, locks ordenados por id de cuenta |
| POST | `/api/v1/treasury/gateway/{account}/settlements` | finance.payments.register | Liquidación (bruto, % comisión, lote) | Idempotency-Key (lote) |
| POST | `/api/v1/treasury/petty-cash/{account}/sessions` | finance.payments.register | Aperturar | UNIQUE sesión/día |
| POST | `/api/v1/treasury/petty-cash/{account}/expenses` | finance.payments.register | Gasto con comprobante (multipart) | Idempotency-Key |
| POST | `/api/v1/treasury/petty-cash/{account}/counts` | finance.payments.register | Acta de arqueo (`kind=surprise|closing`) | lock de cuenta |
| POST | `/api/v1/treasury/counts/{id}/approve` | finance.profitability + approvals.view | Aprobar diferencia | lock_version |
| POST | `/api/v1/treasury/counts/{id}/validate` | finance.invoicing | Visto bueno contable | — |
| POST | `/api/v1/treasury/petty-cash/{account}/replenishments` | finance.payments.register | Solicitar | índice parcial |
| POST | `/api/v1/treasury/replenishments/{id}/approve` · `/reject` | finance.profitability + approvals.view | Resolver | lock_version |
| POST | `/api/v1/treasury/replenishments/{id}/pay` | finance.payments.register | Pagar (crea transferencia) | Idempotency-Key |
| GET | `/api/v1/treasury/cash-flow?horizon=90&include_overdue=1&include_fixed=1` | finance.treasury | Proyección por horizonte y semana | cache Redis 5 min |
| GET | `/api/v1/treasury/fx/revaluation-preview?close_rate=3.74` | finance.treasury | Valorización y diferencias | — |
| POST | `/api/v1/treasury/fx/revaluations` | finance.invoicing | Registrar la revaluación del periodo | UNIQUE periodo |
| POST | `/api/v1/treasury/sync` | finance.treasury | Reproceso manual de la sincronización (normalmente por evento) | idempotente por `source_key` |
| GET | `/api/v1/crm/claim-book` | crm.claims.manage | Hojas (`filter[type]`, `filter[sede]`, `filter[book_status]`, `filter[deadline]`) | — |
| GET | `/api/v1/crm/claim-book/{id}` | crm.claims.manage / consumidor dueño | Detalle | — |
| POST | `/api/v1/crm/claim-book` | crm.claims.manage (presencial) | Registro por personal | Idempotency-Key |
| POST | `/api/v1/portal/claim-book` | sesión cliente/pasajero | Registro desde el portal; `client_id` desde el token | Idempotency-Key |
| POST | `/api/v1/public/{tenant}/claim-book` | público (rate limit 5/min por IP + captcha) | Registro desde el sitio web | Idempotency-Key |
| GET | `/api/v1/portal/claim-book` | sesión cliente/pasajero | "Mis hojas" | — |
| POST | `/api/v1/crm/claim-book/{id}/review` | crm.claims.manage | → in_review + responsable | lock_version |
| POST | `/api/v1/crm/claim-book/{id}/response` | crm.claims.manage | Responder y enviar | Idempotency-Key |
| POST | `/api/v1/crm/claim-book/{id}/response/resend` | crm.claims.manage | Reenviar | — |
| POST | `/api/v1/crm/claim-book/{id}/close` | crm.claims.manage | Cerrar | lock_version |
| PATCH | `/api/v1/crm/claim-book/{id}/links` | crm.claims.manage | Vincular cliente o reserva | — |
| GET | `/api/v1/crm/claim-book/{id}/certificate` | crm.claims.manage / dueño | Constancia PDF (URL firmada) | — |
| GET | `/api/v1/crm/claim-book/reports?group=type|sede|month` · POST `/exports` | crm.claims.manage (+ reports.export) | Reportes y relación anual | job `reports` |
| GET/PUT | `/api/v1/crm/claim-book/settings` | ver crm.claims.manage / editar admin.settings.manage | Parámetros | — |

## 7. Permisos y comportamiento por rol

| Rol | Tesorería | Libro de reclamaciones |
|---|---|---|
| Tenant Admin (U01) | Todo: registra, transfiere, apertura y cierra, hace arqueos y gastos, solicita y paga reposiciones, aprueba (no las propias), valida, registra la diferencia cambiaria | Todo, incluida la configuración (`admin.settings.manage`) |
| Manager (U02) | Solo lectura: saldos, movimientos y flujo. **Aprueba** reposiciones y diferencias de arqueo mayores a la tolerancia. Los botones de registro aparecen deshabilitados con el tooltip "Requiere permiso finance.payments.register" | Gestiona hojas (evaluar, responder, cerrar, vincular, registrar presencial); la configuración es de solo lectura |
| Finanzas (U07) | Operación completa de caja y bancos; aprueba solicitudes ajenas (TES-BR-14 bloquea las propias) | Sin acceso (403) |
| Contabilidad (U08) | Lectura; **valida** arqueos y **registra la diferencia cambiaria**; no registra movimientos | Sin acceso (403) |
| Ventas / Operaciones | Sin acceso (403). El custodio de la caja de Cusco o Arequipa figura como responsable, pero registra a través de Finanzas (supuesto §12) | Sin acceso; ven las hojas vinculadas a sus clientes como casos en Postventa |
| Cliente (U14) / Pasajero (U15) | — | `#/libro-reclamaciones`: formulario con sus datos precargados, reservas propias y "Mis hojas" con estado, respuesta y constancia. Nunca ven hojas de terceros ni datos internos |
| Super Admin | — | — (aislamiento multi-tenant) |

## 8. Eventos, jobs (colas Redis) y notificaciones

| Evento / Job | Cola | Efecto |
|---|---|---|
| `PaymentConfirmed`, `PaymentApplied`, `PaymentReverted` | critical | `TreasurySyncListener` crea el movimiento (idempotente por `source_key`) |
| `SupplierPaymentRegistered` | critical | Egreso por payout |
| `PettyCashExpenseRegistered` | default | Si el saldo < umbral: notificación `caja.umbral` (Portal a Finanzas) |
| `CashCountSigned` (con diferencia > tolerancia) | notifications | `arqueo.diferencia` al Manager (Portal/Reverb) |
| `CashReplenishmentRequested/Approved/Rejected/Paid` | notifications | `caja.reposicion*` al aprobador, solicitante o custodio |
| `FxRevaluationRegistered` | default | Asiento contable (integración contable futura vía `sys_outbox`) |
| `treasury:cash-deposit-alerts` | low, cada hora | TES-BR-09 |
| `treasury:open-session-check` | low, diario 10:00 | Alerta de cajas sin aperturar (TES-BR-05) |
| `treasury:cash-flow-snapshot` | reports, diario 06:00 | Cachea la proyección en Redis |
| `ClaimBookEntryReceived` | notifications + documents | Constancia PDF (`reclamacion.constancia`, email) y aviso al responsable (`reclamacion.nueva`) |
| `ClaimAnswered` | notifications + documents | Email con la respuesta (`reclamacion.respuesta`); `sent_at` al confirmar el proveedor de correo (webhook) |
| `claims:deadline-alerts` | notifications, diario 08:00 (en el prototipo, botón "Alertar vencimientos") | `reclamacion.por_vencer` / `reclamacion.vencida` al responsable y copia al Manager |

## 9. Auditoría

Todas las acciones críticas quedan en `audit_logs` (usuario, IP, correlación, antes/después). Entidades usadas en el prototipo:
- `Movimiento de tesorería`: registrar, gasto_caja, anular.
- `Transferencia`: registrar, liquidar_pasarela.
- `Caja`: aperturar, cerrar.
- `Arqueo`: arqueo, arqueo_cierre, aprobar_diferencia, validar_contable.
- `Reposición de caja`: solicitar, aprobar, rechazar, pagar.
- `Diferencia cambiaria`: registrar.
- `Tesorería/sync`: sincronizar_finanzas.
- `Libro de reclamaciones`: registrar, evaluar, responder (marca FUERA DE PLAZO), enviar_respuesta, reenviar_respuesta, vincular, cerrar.
- `Configuración/claim_book`: actualizar.
- `Exportación`: relación anual.
- `Job`: claims:deadline-alerts.

Los accesos denegados (403) los audita el router.

## 10. Casos de prueba mínimos

**Pest (backend)**
1. Aislamiento: el tenant A no lista cuentas, movimientos ni hojas del tenant B (RLS + scope).
2. RBAC: Manager `POST /treasury/movements` → 403; Contabilidad `POST /fx/revaluations` → 201; Finanzas `GET /crm/claim-book` → 403; cliente `GET /portal/claim-book` solo devuelve sus hojas.
3. Sincronización: pago aplicado → 1 ingreso; reproceso → 0; reversión → egreso; Yape USD → BCP PEN con monto original y TC.
4. TES-BR-02: transferencia mayor al saldo → 422; dos transferencias concurrentes no dejan saldo negativo (lock).
5. TES-BR-05/06/07: gasto con la caja cerrada → 422; segunda apertura → 409; apertura con conteo distinto → 422.
6. Arqueo con diferencia de −25 y tolerancia 10 → `pending_approval` sin ajuste; aprobación por el mismo firmante → 403; aprobación por el Manager → ajuste `count_adjustment` de 25.
7. Reposición: el solicitante no aprueba (403); el pago crea una transferencia y marca `paid`; anular la transferencia → vuelve a `approved`.
8. Revaluación: la segunda del mismo periodo → 409; el cálculo por promedio ponderado coincide con el fixture.
9. Libro: correlativo concurrente sin duplicados (20 altas paralelas); `due_date` salta fines de semana y el 08/10; menor de edad sin apoderado → 422; cerrar sin respuesta → 409; responder después del vencimiento → auditoría "fuera de plazo".
10. Idempotencia: el mismo `Idempotency-Key` en el registro público no crea dos hojas.

**Playwright (E2E)**
- TES-E2E-01 (U07): registrar un gasto en Caja Cusco → cerrar la caja con arqueo cuadrado → solicitar reposición; U02 aprueba; U07 paga; saldo = fondo fijo.
- TES-E2E-02 (U02): los botones de registro están deshabilitados; aprobar ARQ-0040.
- TES-E2E-03 (U08): registrar la diferencia cambiaria del periodo; la segunda vez muestra un aviso.
- LR-E2E-01 (U14): enviar una hoja desde el portal → aparece en "Mis hojas" con el correlativo LR-LIM; U02 la ve, responde y cierra; U14 ve la respuesta.
- LR-E2E-02 (U15): hoja del pasajero con la reserva precargada; un usuario interno sin `crm.claims.manage` abre `#/crm/reclamaciones` → 403.
- Responsive a 400 px sin scroll horizontal en todas las rutas.

## 11. Cambios y decisiones respecto a la documentación inicial

- **Módulo nuevo: no estaba en la documentación inicial.** Motivo: el cliente pidió completar el SaaS. La documentación cubría obligaciones (CxC/CxP) y conciliación, pero no la **posición de caja y bancos**, las cajas chicas por sede ni el flujo proyectado, que un ERP turístico necesita para programar pagos a proveedores con prepago. El **Libro de Reclamaciones** es una obligación legal en Perú para quien presta servicios al consumidor final y no figuraba como proceso.
- La tesorería no duplica fuentes de verdad (Regla transversal 3): los ingresos y egresos nacen en Pagos y en CxP y se reflejan por evento; tesorería solo registra lo que le es propio (transferencias, caja chica, arqueos, gastos bancarios, revaluación).
- La pasarela de pagos se modela como **cuenta de tránsito**: el cobro con tarjeta entra en la fecha de la venta y la liquidación posterior acredita el neto al banco y la comisión como gasto. Así se concilia con el abono real.
- Las hojas del libro **reutilizan `DB.claims`** (casos de postventa del CRM) con `book: true` y un vocabulario de estados propio (`bookStatus`) mapeado al del CRM, para que Postventa y Cliente 360 las muestren sin cambios. El prototipo expone `window.ClaimBook.register(data)` para que el sitio web público (sitio.js) registre hojas con el mismo correlativo y plazo.
- Se precisaron la segregación de funciones (TES-BR-14) y la valorización USD por promedio ponderado (TES-BR-18).

## 12. Supuestos y puntos a validar con negocio

1. **Validar la normativa vigente con asesoría legal:** plazo (15 días hábiles, parámetro), contenido mínimo de la hoja, formato de la constancia, aviso visible en cada sede, conservación (se asume un mínimo de 2 años), reporte al SIREC/INDECOPI y tratamiento de datos personales (Ley 29733).
2. Si los custodios de caja de Cusco (Operaciones) y Arequipa (Ventas) deben registrar sus propios gastos. En el prototipo lo hacen Finanzas o el Tenant Admin; una alternativa es un permiso `finance.petty_cash.operate` acotado a su sede.
3. Montos de tolerancia (S/ 10), tope por gasto (S/ 500), declaración jurada (S/ 150), umbral de reposición (40 %) y comisión de la pasarela (4 %): son parámetros iniciales.
4. Egresos fijos del flujo proyectado (planilla, alquileres, tributos): se ingresan manualmente hasta integrar con planillas o contabilidad.
5. Fuente del TC de cierre (SBS venta) y plan contable (cuentas 676/776). La generación de asientos queda para la integración contable.
6. Los extractos bancarios siguen en Conciliación bancaria (`bankLines`); tesorería solo muestra el vínculo.
7. Si se requiere un libro también para "Producto" o solo para "Servicio". Se dejaron ambas opciones.
8. La respuesta al consumidor menor de edad se envía al correo consignado (normalmente el del apoderado).
