# Finanzas y Contabilidad

Fuente: `proto/assets/js/modules/finanzas.js` (1 358 líneas), paneles `financePanel` / `accountingPanel` de `proto/assets/js/modules/dashboard.js`, `core.js` (ACCESS, STATUS, Auth, Seq, Audit, Notify) y `data.js` (colecciones `receivables`, `payments`, `payables`, `commissions`, `invoices`, `bankLines`, `b2bAgencies`, `settings`, `monthly`). Se documenta lo que el prototipo hace; lo que se recomienda distinto está marcado **[Decisión]** y resumido en §11–§12.

Convenciones de este documento: `OP` = permiso `finance.payments.register` (en el prototipo es el "perfil operativo de tesorería" y se exige **además** del permiso específico de cada acción). Tolerancia de redondeo = `0.005` en comparaciones de montos; `r2()` = redondeo a 2 decimales. "S/" = consolidación en PEN al tipo de cambio conservado del documento.

---

## 1. Propósito y procesos cubiertos

Gestiona el ciclo económico de la reserva, desde la obligación de cobro hasta el cierre financiero, y la emisión de comprobantes electrónicos. Flujo oficial (DOC-PROC-001 §15), tal como lo muestra la pantalla Rentabilidad:

`Cotización → Reserva → Anticipo → Cobros → Facturación → Costos → Pagos a proveedores → Comisiones → Rentabilidad → Liquidación / cierre`

| Proceso | Nombre | Pantalla | Estado en prototipo |
|---|---|---|---|
| PROC-040 | Cuenta por cobrar: Cargo → Obligación → Vencimiento → Pago → Aplicación → Saldo → Pagada | `#/finanzas/cobros` | Completo (las CxC se crean en Reservas/B2B, aquí se gestionan) |
| PROC-041 | Anticipo: Solicitud → Importe → Obligación → Pago → Aplicación → Saldo | Modal "Solicitar anticipo" | Completo |
| PROC-042 | Pago: Recibir → Validar → Registrar → Confirmar → Aplicar → Actualizar saldo → Auditar | `#/finanzas/pagos` | Completo |
| PROC-043 | Reversión: Solicitud → Validar → Revertir → Desaplicar → Recalcular → Auditar | Pagos / detalle CxC | Completo |
| PROC-044 | Cuenta por pagar: Costo/obligación → Validar → Aprobar → Pagar → Aplicar → Cerrar | `#/finanzas/proveedores` | Completo (sin estado "parcial", ver §11) |
| PROC-045 | Costos: Detectar → Registrar → Asociar → Validar → Aprobar → Impactar rentabilidad | Modal "Registrar costo" | Completo (costo = CxP de tipo `costo_adicional`) |
| PROC-046 | Pago a proveedor: Obligación → Aprobación → Pago → Confirmación → Aplicación | CxP → "Programar" / "Pagar" | Completo |
| PROC-047 | Comisiones: Venta → Regla → Cálculo → Revisión → Aprobación → Pago → Registro | `#/finanzas/comisiones` | Completo (reglas fijas en código) |
| PROC-048 | Rentabilidad: Ingresos → Costos → Comisiones → Reglas → Margen → Dashboard; + Liquidación/cierre | `#/finanzas/rentabilidad` | Completo |
| PROC-049 | Reconciliación: Importar → Normalizar → Matching → Excepción → Resolver → Cerrar | `#/finanzas/conciliacion` | Completo (importación simulada) |
| PROC-050 | Facturación: Venta/Cobro → Validación fiscal → Preparación → Emisión → Respuesta → Registro → Entrega → Auditoría | `#/finanzas/facturacion` | Completo con OSE **simulado** |
| — (nuevo) | Cierre contable de periodo (checklist + cierre) | `#/dashboard` (rol Contabilidad) | Completo |

Fuera de alcance de este documento: `#/finanzas/tesoreria` (`finance.treasury`, módulo `tesoreria.js` vacío en el prototipo), portal de pagos del cliente/B2B (canales), creación de CxC al convertir/modificar/cancelar reservas (Reservas; aquí se listan sus efectos en §8).

### 1.1 Facturación electrónica Perú (SUNAT vía OSE/PSE) — marco a implementar

> **La integración concreta está POR VALIDAR** (DOC-PROC-001 §16 y PROC-064: "no declarar una integración fiscal concreta como productiva hasta validar proveedor, contrato, API y condiciones"; "no inventar APIs, credenciales, costos o límites"). El prototipo simula la respuesta del OSE (aceptación a los 1,4 s). Todo dato normativo de esta sección es referencia para análisis y **debe confirmarlo el contador del tenant y el proveedor OSE/PSE elegido** antes de desarrollar.

| Tema | Prototipo | Referencia normativa a validar (SUNAT, UBL 2.1) |
|---|---|---|
| Modelo de emisión | Botón "Validar y emitir" → estado `emitido` → respuesta simulada | **OSE** (Operador de Servicios Electrónicos: valida y emite el CDR en nombre de SUNAT) o **PSE** (Proveedor de Servicios Electrónicos: genera/firma/envía por cuenta del emisor). El sistema se integra con UN adaptador `FiscalProvider` (Regla transversal 20); el proveedor se elige por tenant. |
| Tipos de comprobante | Factura, Boleta, Nota de crédito | Catálogo 01: `01` Factura, `03` Boleta de venta, `07` Nota de crédito, `08` Nota de débito (no implementada, ver §12). |
| Series | `F001-` (factura), `B001-` (boleta) vía `settings.series`; NC `FC01-` si modifica factura, `BC01-` si modifica boleta | Serie alfanumérica de 4 caracteres: facturas y sus notas empiezan con **F**, boletas y sus notas con **B**; correlativo hasta 8 dígitos. Una serie por tipo y punto de emisión (sede). |
| Datos obligatorios mínimos | RUC/razón social/dirección del emisor; tipo y número; fecha; adquirente (tipo y número de documento, nombre, domicilio/país); moneda y TC; descripción; valor unitario; op. gravada/exportación, op. exonerada (0), IGV 18 %, importe total; monto en letras; referencia a reserva/CxC; en NC: documento que modifica y motivo | Además: tipo de operación (Catálogo 51: `0101` venta interna; `02xx` exportación de servicios), código de afectación IGV por línea (Catálogo 07: `10` gravado, `40` exportación), unidad de medida (`ZZ` servicios), tipo de documento del adquirente (Catálogo 06: `6` RUC, `1` DNI, `4` CE, `7` Pasaporte, `0` no domiciliado sin RUC), leyenda de monto en letras (Catálogo 52 `1000`), **forma de pago Contado/Crédito con cuotas** en facturas a crédito, motivo de NC (Catálogo 09), firma digital, hash/código QR en la representación impresa. |
| Estados | `emitido` → `aceptado` / `observado`; `anulado` pintado en el flujo pero sin transición | Respuesta del CDR: aceptado, aceptado con observaciones, rechazado. Anulación por Comunicación de Baja (facturas) o Resumen Diario (boletas), con plazo legal. Ver §4.8. |
| Exportación de servicios a no domiciliados | Switch "Adquirente domiciliado en Perú" (por defecto `país del cliente = Perú`). Si no domiciliado → operación "exportación", sin IGV, se permite **boleta** con pasaporte/CE | Ley del IGV art. 33 y Apéndice V: solo ciertos servicios califican como exportación (p. ej. hospedaje y alimentación a no domiciliados con pasaporte y permanencia ≤ 60 días; paquete turístico vendido a agencias/operadores no domiciliados). La venta directa de servicios de agencia a un turista extranjero **no necesariamente es exportación**. En exportación el comprobante suele ser **factura** aun sin RUC del adquirente. **Riesgo fiscal alto — ver §12 P-01.** |
| Notas de crédito | Solo sobre comprobante `aceptado` del mismo cliente, importe ≤ importe del referenciado, motivo libre (default "Anulación de la operación") | Motivo por Catálogo 09 (`01` anulación de la operación, `06` devolución total, `07` devolución por ítem, `09` disminución en el valor, `13` ajuste de montos/fechas de pago…). La suma de NC no debe exceder el comprobante original. |
| Tipo de cambio | `settings.fx` (manual diario "SBS referencial"), editable en la emisión; se conserva en el comprobante (Regla 15) | TC a usar (SBS/SUNAT, compra/venta, fecha de emisión) a definir con contabilidad. |
| Anticipos | Se emite comprobante por CxC cobrada (anticipo 40 % → factura por el anticipo) | La factura final debe **deducir los anticipos** facturados (campos de anticipo del UBL). No implementado. Ver §12 P-05. |

---

## 2. Pantallas

Todas en el layout back-office. Los detalles se abren como *drawer* sobre el listado (URL con código). Los enlaces cruzados (cliente, reserva, proveedor, CxC, pago, comprobante) solo se renderizan como enlace si el rol puede abrir la ruta destino (`Access.canRoute`); si no, texto plano.

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/finanzas` | `/finanzas` → redirige a `/finanzas/cobros` | Alias de Cuentas por cobrar | Tenant Admin, Manager, Finanzas, Contabilidad |
| `#/finanzas/cobros` | `/finanzas/cobros` | KPIs (por cobrar, vencido, por vencer 7 d **o** "cobrado por facturar" para Contabilidad, cobrado en el mes), alerta de vencidas, aging 4 tramos, próximos 6 vencimientos, tabla de CxC (filtros estado/moneda, exportar CSV), acciones "Solicitar anticipo", "Registrar pago", "Cobrar", recordatorio | Tenant Admin, Manager, Finanzas, Contabilidad (`finance.receivables`) |
| `#/finanzas/cobros/{CXC}` | `/finanzas/cobros/[code]` | Ficha de la obligación: flujo PROC-040, datos, pagos (validar/revertir por fila), comprobantes de la reserva, historial de auditoría, notificaciones; acciones recordatorio / emitir comprobante / registrar pago | Ídem |
| `#/finanzas/pagos` y `#/finanzas/pagos/{PAG}` | `/finanzas/pagos`, `/finanzas/pagos/[code]` (drawer) | KPIs (pendientes, aplicados en el mes, revertidos, rechazados), alerta de pendientes, diagramas PROC-042/043, tabla (filtros estado/método), drawer con flujo, historial de estados y acciones | `finance.payments.register` o `finance.receivables` → Tenant Admin, Manager, Finanzas, Contabilidad |
| `#/finanzas/proveedores` y `/{CXP}` | `/finanzas/cuentas-por-pagar`, `/[code]` | KPIs (por pagar, vencido, por aprobar, pagado en el mes + programados 7 d), diagramas PROC-044/045/046, pestañas **Por documento** (tabla), **Por proveedor** (saldos agrupados → drawer del proveedor), **Costos registrados**; acciones Aprobar / Programar / Pagar / Registrar costo | `finance.payables` → Tenant Admin, Manager, Finanzas |
| `#/finanzas/comisiones` y `/{COM}` | `/finanzas/comisiones`, `/[code]` | KPIs por estado y "ventas sin cálculo", reglas vigentes, resumen por beneficiario, tabla; acciones Calcular pendientes / Aprobar / Pagar / Recalcular | `finance.commissions` → Tenant Admin, Manager, Finanzas |
| `#/finanzas/rentabilidad` y `/{RES}` | `/finanzas/rentabilidad`, `/finanzas/rentabilidad/[reservationCode]` (modal de liquidación) | Flujo financiero oficial, KPIs (ingresos, costos, comisiones, margen bruto %, utilidad neta), gráficos mensuales, margen por producto/vendedor/canal, tabla por reserva con semáforo; modal **Liquidación / cierre** (estado de resultados, flujo de caja, cobros, costos, comisiones, checklist, cerrar liquidación, enviar estado de cuenta) | `finance.profitability` → Tenant Admin, Manager, Finanzas |
| `#/finanzas/facturacion` y `/{NRO}` | `/finanzas/facturacion`, `/[number]` | Aviso OSE, KPIs (emitidos en el mes, aceptados, observados, cobrado por facturar), flujo PROC-050, cobros pendientes de facturar (botón Emitir), tabla de comprobantes (filtros tipo/estado), drawer (vista previa, consultar OSE, corregir y reenviar, NC, entregar), modal de emisión con validación fiscal en vivo, vista previa imprimible | Ruta: `finance.invoicing` o `finance.profitability`. Contenido: `finance.invoicing` → Tenant Admin, Finanzas, Contabilidad. Manager entra a la ruta pero ve aviso "sin permiso" (ver §7, §11) |
| `#/finanzas/conciliacion` | `/finanzas/conciliacion` | KPIs por estado de línea, flujo PROC-049, importación de extracto (banco + archivo), tabla de líneas (Confirmar/Rechazar/Resolver), cierres de conciliación | Ruta: `finance.payments.register` o `finance.profitability`. Contenido: `finance.payments.register` → Tenant Admin, Finanzas. Manager ve aviso; Contabilidad 403 |
| `#/dashboard` (rol `finance`) | `/dashboard` | Panel de Finanzas (§2.1) | Finanzas |
| `#/dashboard` (rol `accounting`) | `/dashboard` | Panel de Contabilidad con cierre de periodo (§2.2) | Contabilidad |

### 2.1 Panel de Finanzas (`financePanel`)
| Bloque | Cálculo en el prototipo |
|---|---|
| Acciones | "Conciliación" (enlace), "Registrar pago" (`btnIf('finance.payments.register')` → deshabilitado con *title* si no tiene) |
| Alerta | N pagos `pendiente` "por validar" |
| KPI Caja del mes | Pagos `aplicado`/`confirmado` con fecha en el mes, a PEN con **TC actual** (`toPEN`) |
| KPI CxC abierta | CxC con `status` almacenado ≠ pagada/cancelada; nº y monto vencido (due < hoy) |
| KPI Pagos por validar | Pagos `pendiente` |
| KPI CxP a 7 días | CxP `status ≠ pagada` con vencimiento ≤ hoy+7 (incluye vencidas) |
| KPI Margen bruto del mes | `DB.monthly` (ventas−costo)/ventas del último mes; "Meta 28 %" (texto fijo) |
| Margen por reserva | Reservas ≠ borrador/cancelada; venta = Σ `price` de servicios no cancelados, costo = Σ `cost`; semáforo **≥25 % En meta / ≥15 % Vigilar / <15 % Bajo mínimo**; clic → reserva |
| CxC por antigüedad | 5 tramos: Por vencer, 1–30, 31–60, 61–90, >90 días + lista de vencidas |
| Listas | Pagos por validar; CxP por vencer 7 días (marca "sin factura" si falta `providerInvoice` cargada en la extranet); Comisiones `calculada` por aprobar |
| Gráficos | Ventas vs costo 6 meses; caja del mes por medio de pago |

### 2.2 Panel de Contabilidad (`accountingPanel`)
| Bloque | Cálculo en el prototipo |
|---|---|
| Acciones | "CxC" (enlace), "Facturación" (`btnIf('finance.invoicing')`) |
| Alerta | Comprobantes `observado`/`rechazado` con su detalle |
| KPIs | Comprobantes del mes (monto sin NC); observados/rechazados; **cobros sin comprobante** (pago aplicado/confirmado sin comprobante ≠NC de la misma reserva con importe igual ±0,50 PEN); CxC abiertas |
| Tabla | Comprobantes emitidos (clic → Facturación) |
| Cierre del periodo | 7 controles: 4 automáticos (sin observados; todo cobro aplicado con comprobante; toda reversión con NC de la reserva; conciliación sin líneas ≠ conciliado — enlace solo si puede abrir la ruta) y 3 manuales (TC de cierre SBS registrado; revisión de CxC vencidas y provisión de cobranza dudosa; registro de ventas conciliado con IGV). Barra de progreso, botón "Cerrar periodo" |
| Listas | Cobros sin comprobante; CxC abiertas |

---

## 3. Modelo de datos PostgreSQL

Todas las tablas: `tenant_id uuid not null` FK `tenants(id)`, RLS `tenant_isolation`, `created_by`, `updated_by`, `created_at`, `updated_at`. Dinero `numeric(14,2)`, TC `numeric(12,6)`. Estados `varchar(30)` + CHECK; transiciones solo por servicios de dominio. Sin borrado físico (Regla 24): `deleted_at` donde se indica. FK externas a otros módulos: `booking_reservations`, `booking_reservation_services`, `crm_clients`, `catalog_providers`, `iam_users`, `channel_b2b_agencies`, `sys_documents`.

### 3.1 `fin_receivables` (CxC / obligación de cobro)
| columna | tipo | null | notas / FK / índice |
|---|---|---|---|
| id | uuid | no | PK (UUID v7) |
| tenant_id | uuid | no | FK tenants |
| code | varchar(30) | no | `CXC-00710`; `UNIQUE(tenant_id, code)`; `sys_sequences` |
| reservation_id | uuid | no | FK booking_reservations; índice |
| client_id | uuid | no | FK crm_clients; índice (titular de la obligación) |
| kind | varchar(20) | no | CHECK `anticipo, saldo, cargo, ajuste, penalidad, b2b` (prototipo: `anticipo` explícito; el resto inferido del concepto; ver §11) |
| concept | varchar(255) | no | "Anticipo 50% — …" |
| amount | numeric(14,2) | no | CHECK `amount > 0` |
| currency | char(3) | no | PEN/USD = moneda de la reserva |
| fx_rate | numeric(12,6) | sí | Obligatorio si `currency <> tenant.base_currency` (CHECK); TC conservado al crear (Regla 15) |
| paid_amount | numeric(14,2) | no | default 0; = Σ aplicaciones vigentes; CHECK `0 <= paid_amount <= amount` |
| due_date | date | no | índice parcial `(tenant_id, due_date) WHERE status IN ('abierta','parcial','vencida')` |
| status | varchar(20) | no | CHECK `abierta, parcial, vencida, pagada, cancelada`; índice `(tenant_id, status)` |
| cancelled_at / cancel_reason | timestamptz / text | sí | Lo fija Reservas al cancelar |
| last_reminder_at | timestamptz | sí | Denormalizado de `fin_receivable_reminders` |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | SoftDeletes |

Índices: `(tenant_id, reservation_id)`, `(tenant_id, client_id)`, `(tenant_id, created_at desc)`.

### 3.2 `fin_receivable_reminders`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| receivable_id | uuid | no | FK fin_receivables; índice |
| channels | varchar(60) | no | `email`, `email+whatsapp` (WhatsApp solo si el cliente tiene teléfono) |
| sent_by | uuid | sí | FK iam_users; null = scheduler |
| sent_at | timestamptz | no | |
| balance_at_send | numeric(14,2) | no | Saldo informado |

### 3.3 `fin_payments` (pagos recibidos y reembolsos)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | `PAG-01920`; `UNIQUE(tenant_id, code)` |
| reservation_id | uuid | no | FK booking_reservations; índice |
| receivable_id | uuid | sí | FK fin_receivables; null en reembolsos (`kind='reembolso'`) y pagos aún no aplicados de origen externo |
| kind | varchar(20) | no | CHECK `cobro, reembolso`; default `cobro` |
| source | varchar(20) | no | CHECK `backoffice, reserva, portal_cliente, b2b, ecommerce, sistema` |
| method | varchar(40) | no | FK lógica a `fin_payment_methods.code` (valores prototipo: Transferencia BCP/Interbank/BBVA, Tarjeta, Yape, Plin, Efectivo, Crédito B2B, Tarjeta (pasarela/e-commerce/POS), Reembolso) |
| amount | numeric(14,2) | no | CHECK `> 0` |
| currency | char(3) | no | Igual a la de la CxC |
| fx_rate | numeric(12,6) | sí | Obligatorio si USD |
| paid_on | date | no | Fecha de pago declarada |
| reference | varchar(80) | sí | N° de operación; CHECK de negocio en servicio (FIN-BR-08) |
| note | text | sí | Observación |
| evidence_document_id | uuid | sí | FK sys_documents (voucher) |
| status | varchar(20) | no | CHECK `pendiente, confirmado, aplicado, rechazado, revertido`; índice `(tenant_id, status)` |
| reject_reason | text | sí | Obligatorio si `rechazado` |
| retries | smallint | no | default 0 |
| reverted_at / reverted_by / revert_reason | timestamptz / uuid / text | sí | Obligatorios si `revertido` (CHECK) |
| registered_by | uuid | sí | FK iam_users; null = Sistema (e-commerce) |
| lock_version | integer | no | default 0 |

### 3.4 `fin_payment_applications`
Modela la "Aplicación" (PROC-042) y la "Desaplicación" (PROC-043). MVP = 1 aplicación vigente por pago (igual que el prototipo).
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| payment_id | uuid | no | FK fin_payments; `UNIQUE(payment_id) WHERE reversed_at IS NULL` |
| receivable_id | uuid | no | FK fin_receivables; índice |
| amount | numeric(14,2) | no | = payment.amount (sin aplicación parcial en MVP) |
| applied_at / applied_by | timestamptz / uuid | no | |
| reversed_at / reversed_by | timestamptz / uuid | sí | Desaplicación |

### 3.5 `fin_status_history` (historial de estados de pagos, comisiones y comprobantes; `history[]` del prototipo)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| entity_type | varchar(30) | no | CHECK `payment, commission, invoice, payable` |
| entity_id | uuid | no | índice `(tenant_id, entity_type, entity_id)` |
| status | varchar(30) | no | |
| note | text | sí | "Pago registrado y validado", "Reintento #1", "Respuesta OSE simulada"… |
| user_id | uuid | sí | null = Sistema |
| created_at | timestamptz | no | |

### 3.6 `fin_payables` (CxP y costos adicionales)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | `CXP-00402`; `UNIQUE(tenant_id, code)` |
| provider_id | uuid | no | FK catalog_providers; índice |
| reservation_id | uuid | sí | FK booking_reservations; índice |
| reservation_service_id | uuid | sí | FK booking_reservation_services ("Costo general de la reserva" si null) |
| kind | varchar(20) | no | CHECK `servicio, costo_adicional` |
| cost_type | varchar(40) | sí | CHECK `costo_adicional, ajuste_tarifa, penalidad_proveedor, gasto_operativo_destino`; obligatorio si `kind='costo_adicional'` |
| concept | varchar(255) | no | |
| amount | numeric(14,2) | no | CHECK `> 0` |
| currency | char(3) | no | |
| fx_rate | numeric(12,6) | sí | Obligatorio si USD |
| due_date | date | no | |
| paid_amount | numeric(14,2) | no | default 0; = Σ payouts; CHECK `<= amount` |
| status | varchar(20) | no | CHECK `abierta, aprobada, parcial, pagada, vencida, cancelada` (`parcial` y `cancelada` = **[Decisión]** §11) |
| approved_at / approved_by | timestamptz / uuid | sí | Aprobación independiente del estado de vencimiento (una CxP puede estar vencida y aprobada) |
| supplier_doc_number | varchar(30) | sí | Documento del proveedor ingresado por Finanzas (ej. F001-000123) |
| evidence_note | text | sí | "Evidencia / sustento" |
| scheduled_date | date | sí | Programación de pago; se limpia al quedar pagada |
| scheduled_bank_account_id | uuid | sí | FK fin_bank_accounts |
| scheduled_by | uuid | sí | |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | |

Índices: `(tenant_id, status)`, `(tenant_id, due_date)`, `(tenant_id, kind)`. La factura que el proveedor sube en la extranet vive en `supplier_invoices(payable_id, number, issue_date, amount, document_id, uploaded_by, uploaded_at)` (spec de Extranet de proveedores); Finanzas la lee.

### 3.7 `fin_payable_payouts` (pagos a proveedores)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | `PPR-1001`; `UNIQUE(tenant_id, code)` |
| payable_id | uuid | no | FK fin_payables; índice |
| paid_on | date | no | |
| amount | numeric(14,2) | no | CHECK `> 0` |
| fx_rate | numeric(12,6) | sí | Obligatorio si la CxP es USD |
| method | varchar(30) | no | CHECK `transferencia, transferencia_cci, cheque_gerencia, efectivo_caja_chica, cargo_bancario` |
| bank_account_id | uuid | sí | FK fin_bank_accounts |
| operation_ref | varchar(80) | no | N° de operación |
| bank_line_id | uuid | sí | FK fin_bank_lines (si nació de conciliación) |

### 3.8 `fin_bank_accounts`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| bank | varchar(20) | no | BCP, Interbank, BBVA… |
| account_type | varchar(20) | no | `cta_cte`, `ahorros` |
| currency | char(3) | no | Filtra cuentas elegibles por moneda de la CxP |
| number / cci | varchar(30) / varchar(20) | no / sí | |
| label | varchar(80) | no | "BCP Cta. Cte. PEN 193-…" |
| active | boolean | no | default true |

### 3.9 `fin_payment_methods` (configurable)
`id, tenant_id, code varchar(40) UNIQUE(tenant_id, code), label, requires_reference boolean, is_b2b_credit boolean, active boolean`. Semilla: los 8 métodos de `PAY_METHODS` (Efectivo y Crédito B2B con `requires_reference=false`).

### 3.10 `fin_commission_rules`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| beneficiary_kind | varchar(20) | no | CHECK `vendedor, agencia_b2b` |
| condition | varchar(30) | no | CHECK `directo_ecommerce, corporativo_grupos, b2b_convenio` |
| pct | numeric(5,2) | sí | 3.00 / 2.50; null en `b2b_convenio` (usa `channel_b2b_agencies.commission_pct`) |
| base_type | varchar(20) | no | CHECK `neto_sin_igv, total` |
| valid_from / valid_to | date | no / sí | |
| active | boolean | no | |

### 3.11 `fin_commissions`
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(30) | no | `COM-0301`; `UNIQUE(tenant_id, code)` |
| reservation_id | uuid | no | FK; `UNIQUE(tenant_id, reservation_id, kind) WHERE deleted_at IS NULL` (idempotencia del cálculo) |
| kind | varchar(20) | no | CHECK `vendedor, agencia_b2b` |
| beneficiary_user_id | uuid | sí | FK iam_users (vendedor) |
| beneficiary_agency_id | uuid | sí | FK channel_b2b_agencies; CHECK exactamente uno de los dos |
| rule_id | uuid | sí | FK fin_commission_rules |
| rule_description | varchar(255) | no | Texto de la regla aplicada (snapshot) |
| base_amount | numeric(14,2) | no | |
| pct | numeric(5,2) | no | |
| amount | numeric(14,2) | no | `r2(base × pct / 100)` |
| currency | char(3) | no | = reserva |
| fx_rate | numeric(12,6) | sí | USD |
| status | varchar(20) | no | CHECK `calculada, aprobada, pagada`; índice `(tenant_id, status)` |
| approved_at / approved_by | timestamptz / uuid | sí | |
| paid_at / payment_method / payment_ref | timestamptz / varchar(40) / varchar(80) | sí | Métodos: "Planilla del mes", "Transferencia BCP", "Nota de crédito a cuenta B2B" |
| lock_version | integer | no | |
| deleted_at | timestamptz | sí | |

### 3.12 `fin_settlements` (liquidación / cierre financiero de reserva)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| reservation_id | uuid | no | FK; `UNIQUE(tenant_id, reservation_id)` |
| closed_at / closed_by | timestamptz / uuid | no | |
| income_pen, cost_pen, commissions_pen, net_pen | numeric(14,2) | no | Snapshot |
| gross_margin_pct | numeric(6,2) | no | |
| snapshot | jsonb | no | Checklist, líneas de cobros/costos/comisiones al cierre |

### 3.13 `fin_document_series`
`id, tenant_id, doc_type char(2)` CHECK `01,03,07,08`, `series char(4)` (`F001`, `B001`, `FC01`, `BC01`), `next_number integer`, `sede_id uuid null`, `active boolean`; `UNIQUE(tenant_id, series)`. Numeración con `SELECT … FOR UPDATE` en la misma transacción de la emisión.

### 3.14 `fin_invoices` (comprobantes electrónicos)
| columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| number | varchar(20) | no | `F001-00001482`; `UNIQUE(tenant_id, number)` |
| doc_type | char(2) | no | CHECK `01` factura, `03` boleta, `07` NC, `08` ND |
| series | char(4) | no | FK lógica fin_document_series |
| correlative | integer | no | `UNIQUE(tenant_id, series, correlative)` |
| issue_date | date | no | = hoy en el prototipo; índice `(tenant_id, issue_date desc)` |
| period | char(7) | no | `YYYY-MM`; bloqueado si el periodo está cerrado (FIN-BR-48) |
| client_id | uuid | no | FK crm_clients |
| customer_doc_type | varchar(10) | no | CHECK `RUC, DNI, PASAPORTE, CE, SIN_DOC` (mapear a Catálogo 06 en el adaptador) |
| customer_doc_number | varchar(20) | no | |
| customer_name | varchar(200) | no | Razón social / nombre |
| customer_address | varchar(255) | sí | Domicilio / país |
| customer_domiciled | boolean | no | Switch del modal |
| reservation_id | uuid | no | FK |
| receivable_id | uuid | sí | FK fin_receivables (origen) |
| operation_type | varchar(20) | no | CHECK `gravada, exportacion` (+ `sunat_operation_code varchar(4)` a definir) |
| currency | char(3) | no | = CxC |
| fx_rate | numeric(12,6) | sí | USD |
| taxable_amount | numeric(14,2) | no | Gravada: `r2(total/1.18)`; exportación: total |
| igv_amount | numeric(14,2) | no | Gravada: total − base; exportación: 0 |
| exempt_amount | numeric(14,2) | no | 0 |
| total_amount | numeric(14,2) | no | CHECK `> 0` |
| amount_in_words | varchar(255) | no | "SON: … CON 00/100 SOLES/DÓLARES AMERICANOS" |
| description | varchar(500) | no | |
| ref_invoice_id | uuid | sí | FK fin_invoices; obligatorio si `doc_type IN ('07','08')` (CHECK) |
| credit_note_reason_code | char(2) | sí | Catálogo 09 **[Decisión]** (prototipo: texto libre) |
| credit_note_reason | varchar(255) | sí | |
| status | varchar(20) | no | CHECK `pendiente_envio, emitido, aceptado, observado, rechazado, anulado` (`pendiente_envio`, `rechazado` = **[Decisión]**) |
| fiscal_provider | varchar(30) | sí | OSE/PSE usado |
| ose_ticket | varchar(80) | sí | |
| ose_response_code / ose_response_message | varchar(10) / text | sí | Observación mostrada al usuario |
| ose_responded_at | timestamptz | sí | |
| xml_document_id / cdr_document_id / pdf_document_id | uuid | sí | FK sys_documents (hash) |
| hash_code | varchar(64) | sí | Para QR |
| delivered_at | timestamptz | sí | Entrega email + portal |
| issued_by | uuid | no | |
| lock_version | integer | no | |

Índices: `(tenant_id, status)`, `(tenant_id, reservation_id)`, `(tenant_id, client_id)`, `(tenant_id, doc_type)`. Nunca `deleted_at`: un comprobante solo se anula por NC o baja.

### 3.15 `fin_invoice_lines`
`id, tenant_id, invoice_id FK, line_no smallint, description, quantity numeric(12,3) default 1, unit_code varchar(3) default 'ZZ', unit_value numeric(14,2), igv_affectation varchar(2)` (`10` gravado / `40` exportación), `line_amount numeric(14,2), igv_amount numeric(14,2)`. El prototipo emite **una sola línea** por comprobante.

### 3.16 `fin_fiscal_events` (bitácora de integración fiscal, PROC-064/065)
`id, tenant_id, invoice_id FK, action varchar(20)` CHECK `send, status, resend, void`, `attempt smallint, request_hash varchar(64), response_code, response_message, payload_document_id, status varchar(20)` CHECK `ok, error_temporal, error_definitivo`, `created_at`.

### 3.17 `fin_bank_statements`, `fin_bank_lines`, `fin_reconciliation_closures`
`fin_bank_statements`: `id, tenant_id, bank varchar(20), bank_account_id uuid null, filename, document_id, format varchar(10)` (csv/txt/xlsx), `lines_count, imported_by, imported_at`.

| `fin_bank_lines` columna | tipo | null | notas |
|---|---|---|---|
| id | uuid | no | PK |
| code | varchar(20) | no | `BNK-1`; `UNIQUE(tenant_id, code)` |
| statement_id | uuid | sí | FK fin_bank_statements |
| bank | varchar(20) | no | |
| value_date | date | no | |
| description | varchar(255) | no | Normalizada en mayúsculas |
| amount | numeric(14,2) | no | Con signo (+ abono, − cargo); CHECK `<> 0` |
| currency | char(3) | no | PEN en el prototipo |
| matched_payment_id | uuid | sí | FK fin_payments |
| matched_payable_id | uuid | sí | FK fin_payables; CHECK a lo sumo uno de los dos |
| status | varchar(20) | no | CHECK `pendiente, sugerido, excepcion, conciliado` |
| resolution | varchar(20) | sí | CHECK `match, asignado, otro_ingreso, gasto_bancario` |
| resolution_note | text | sí | Sustento obligatorio al resolver excepción |
| resolved_by | uuid | sí | |
| closure_id | uuid | sí | FK fin_reconciliation_closures; fila bloqueada si no es null |

Unicidad: un pago/CxP no puede estar vinculado a dos líneas no-excepción: `UNIQUE(tenant_id, matched_payment_id) WHERE status <> 'excepcion'` (ídem payable).

`fin_reconciliation_closures`: `id, code` (`CON-2026-001`), `period char(7), closed_at, closed_by, lines_count, credits numeric(14,2), debits numeric(14,2), net numeric(14,2), matched_count, others_count`.

### 3.18 `fin_period_closes`, `fin_period_close_checks`
`fin_period_closes`: `id, tenant_id, period char(7)` `UNIQUE(tenant_id, period)`, `status varchar(10)` CHECK `abierto, cerrado`, `closed_at, closed_by`.
`fin_period_close_checks`: `id, period_close_id FK, check_code varchar(20)` CHECK `fx, aging, igv` (manuales), `checked_by, checked_at`; `UNIQUE(period_close_id, check_code)`. Los controles automáticos se calculan, no se guardan (se guarda su resultado en `snapshot jsonb` al cerrar) **[Decisión]**.

### 3.19 `fin_fx_rates` **[Decisión]**
`id, tenant_id, rate_date date, currency char(3), buy numeric(12,6), sell numeric(12,6), source varchar(10)` CHECK `manual, SBS, SUNAT`; `UNIQUE(tenant_id, rate_date, currency)`. Reemplaza `settings.fx` (valor único manual en el prototipo).

### 3.20 Columnas que Finanzas escribe en tablas de otros módulos
| Tabla | Columna | Quién la escribe en el prototipo |
|---|---|---|
| booking_reservations | status (`pendiente_pago` → `confirmada`) | Aplicación de pago cuando todas las CxC no canceladas quedan pagadas |
| channel_b2b_agencies | credit_used | Pago con "Crédito B2B" (suma) y su reversión (resta); reserva B2B a crédito (Canales) |

---

## 4. Máquinas de estado

### 4.1 Cuenta por cobrar (estado derivado en el prototipo: `cxcStatus`)
Orden de evaluación: `cancelada` (almacenado) → `pagada` si saldo ≤ 0 → `vencida` si `due < hoy` → `parcial` si pagado > 0 → `abierta`.

| De | A | Disparador | Quién | Efectos |
|---|---|---|---|---|
| — | abierta | Conversión cotización→reserva (40 %/60 %), reserva B2B, modificación/reprogramación (ajuste), cancelación (penalidad), **Solicitar anticipo** | Reservas/Canales (sistema); Finanzas (`OP`) | Auditoría `crear`/`crear_anticipo`; notificación `pago.solicitud` (email + portal) si se marca |
| abierta/vencida | parcial | Aplicación de pago < saldo | Finanzas | `actualizar_saldo` |
| abierta/parcial/vencida | pagada | Aplicación que deja saldo 0 | Finanzas / sistema (e-commerce) | `actualizar_saldo`; sincroniza reserva `pendiente_pago → confirmada` si todas pagadas |
| abierta/parcial | vencida | Fecha (`due < hoy`) | Scheduler diario **[Decisión]** (prototipo: cálculo al vuelo) | Alerta en listados y panel |
| pagada/parcial | parcial/abierta/vencida | Reversión de un pago aplicado | Finanzas (`finance.payments.revert`) | `recalcular_saldo` |
| abierta/parcial/vencida | cancelada | Cancelación de reserva | Reservas | `cancelar` (las pagadas no se cancelan) |

Barra de flujo UI: `Cargo → Obligación → Vencimiento → Pago → Aplicación → Saldo → Pagada` (vencida pinta falla en "Vencimiento").

### 4.2 Pago
| De | A | Acción | Permiso | Efectos |
|---|---|---|---|---|
| — | confirmado → aplicado | **Registrar pago** (back-office): se crea confirmado y se aplica en la misma operación | `OP` | Historial 2 estados; auditoría `registrar` + `aplicar`; CxC recalculada; reserva sincronizada; `pago.recibido` (email cliente); si Crédito B2B: `credit_used += monto PEN` |
| — | pendiente | Pago reportado por asesor/cliente/portal, reembolso generado por cancelación | Otros módulos | — |
| pendiente | confirmado | **Validar → Confirmar** | `OP` | `validar`; queda por aplicar |
| pendiente | aplicado | **Validar → Confirmar y aplicar** | `OP` | `validar` + flujo de aplicación |
| pendiente | confirmado | **Confirmar match** en conciliación | `OP` | `validar` "confirmado (conciliación BNK-x)" (no aplica) |
| pendiente | rechazado | **Rechazar** (motivo obligatorio) | `OP` | CxC se mantiene; `pago.rechazado` (email) |
| rechazado | pendiente | **Reintentar validación** | `OP` | `retries += 1`; `reintentar` |
| confirmado | aplicado | **Aplicar a CxC** (elige CxC de la misma reserva y moneda con saldo) | `OP` | `aplicar`, `actualizar_saldo`, `pago.recibido` |
| confirmado / aplicado | revertido | **Revertir** (motivo obligatorio) | `finance.payments.revert` | Desaplica, recalcula CxC, devuelve crédito B2B, `pago.revertido` (email). Estado final, sin salida |

Flujo UI: `Pendiente → Confirmado → Aplicado`; alternativos `… → Revertido` y `Pendiente → Validación → Rechazado`.

### 4.3 Cuenta por pagar / costo (estado derivado `cxpStatus`)
Evaluación: `pagada` si saldo ≤ 0 → `vencida` si `due < hoy` → `aprobada` si `approved` → `abierta`.

| De | A | Acción | Permiso | Efectos |
|---|---|---|---|---|
| — | abierta | Generación por servicio de reserva (Reservas/Operaciones) o **Registrar costo** (`kind=costo_adicional`) | `finance.payables` + `OP` (costo) | `Costo · registrar`; costo sin impacto en rentabilidad |
| abierta / vencida (no aprobada) | aprobada (o vencida con `approved_at`) | **Aprobar** (confirmación con advertencias no bloqueantes) | `finance.payables` | `aprobar`; si costo: `Rentabilidad · impacto_costo` |
| aprobada | aprobada (programada) | **Programar pago** (fecha ≥ hoy, cuenta de la moneda) | `finance.payables` + `OP` | `programar_pago` (no cambia estado) |
| aprobada / vencida aprobada | aprobada (saldo > 0) o pagada | **Registrar pago** (payout) | `finance.payables` + `OP` | `pagar`; `pago.proveedor` (email proveedor); si pagada limpia programación |
| aprobada | pagada/…| **Confirmar match** de cargo bancario | `OP` | Payout `cargo_bancario` por `min(|cargo|/TC, saldo)`; `approved=true` |

Flujos UI: CxP `Costo/obligación → Validar → Aprobar → Pagar → Aplicar → Cerrar`; costo `Detectar → Registrar → Asociar → Validar → Aprobar → Impactar rentabilidad`.

**[Decisión]** agregar `parcial` (pagado > 0 y saldo > 0) y `cancelada` (cancelación de servicio/reserva) para alinear con DOC-PROC-001 §5.

### 4.4 Comisión
| De | A | Acción | Permiso | Efectos |
|---|---|---|---|---|
| — | calculada | **Calcular comisiones pendientes** (una por reserva y tipo) o reserva B2B (Canales) | `finance.commissions` + `OP` | `calcular` |
| calculada | aprobada | **Aprobar** | `finance.commissions` | `aprobar` |
| aprobada | pagada | **Registrar pago** (forma + referencia) | `finance.commissions` + `OP` | `pagar`; `comision.pagada` (email) |
| calculada / aprobada | calculada | **Recalcular** (regla vigente) | `finance.commissions` + `OP` | `recalcular`; desde aprobada "vuelve a revisión" |
| pagada | — | Recalcular bloqueado: "Registre un ajuste en el siguiente periodo" | — | — |

### 4.5 Liquidación de reserva (`reservation.settlement`)
`sin liquidar → liquidada` por **Cerrar liquidación** (`finance.profitability` + `OP`) solo si el checklist completo es verdadero (FIN-BR-36). Sin reapertura en el prototipo.

### 4.6 Comprobante electrónico
| De | A | Disparador | Permiso | Efectos |
|---|---|---|---|---|
| — | emitido | **Validar y emitir** (validación fiscal OK) | `finance.invoicing` | Número de serie; `emitir`; envío al OSE (simulado) |
| emitido | aceptado | Respuesta OSE (simulada a 1,4 s) o **Consultar respuesta OSE** | Sistema / `finance.invoicing` | `respuesta_ose` |
| emitido | observado | Respuesta OSE con observación | Sistema | Alerta en listado y panel Contabilidad |
| observado | emitido | **Corregir y reenviar** (TC y/o descripción) | `finance.invoicing` | `reenviar` → nueva respuesta |
| aceptado | aceptado (entregado) | **Entregar / Reenviar al cliente** | `finance.invoicing` | `delivered_at`; `comprobante.emitido` (email + portal) |
| aceptado (no NC) | — | **Nota de crédito** → nuevo comprobante `07` que referencia | `finance.invoicing` | NC sigue su propio ciclo |
| — | anulado | No implementado (el flujo lo dibuja) | — | — |

**[Decisión] estados de producción:** `pendiente_envio` (outbox, antes de respuesta de envío) → `emitido` (enviado, ticket) → `aceptado` | `observado` (aceptado con observaciones: válido, requiere corrección futura) | `rechazado` (inválido: nunca se entrega; numeración consumida) → `anulado` (por NC total o comunicación de baja/resumen, según reglas del proveedor). En SUNAT un comprobante "observado" **ya es válido**; el "corregir y reenviar" del prototipo sobre el mismo número debe revisarse con el OSE (§12 P-03).

Flujo UI: `Venta/Cobro → Validación fiscal → Preparación → Emisión → Respuesta → Registro → Entrega → Auditoría`.

### 4.7 Línea de extracto bancario
| De | A | Acción | Efectos |
|---|---|---|---|
| — | pendiente | **Importar extracto** | 3 líneas normalizadas (simulación) |
| pendiente | sugerido / excepcion | **Ejecutar matching** | `matching` con conteos |
| sugerido | conciliado | **Confirmar** | Efecto sobre pago/CxP (§4.2, §4.3); `confirmar_match` |
| sugerido | excepcion | **Rechazar** | `rechazar_match` |
| excepcion | conciliado | **Resolver** (asignar pago/CxP u "otro ingreso"/"gasto bancario", sustento obligatorio) | `resolver_excepcion` |
| conciliado | cerrada (`closure_id`) | **Cerrar conciliación** (todas las líneas abiertas conciliadas) | `cerrar`; líneas bloqueadas |
Todas requieren `OP`.

### 4.8 Periodo contable
`abierto → cerrado` por **Cerrar periodo** (`finance.invoicing`, todos los controles OK). Los controles manuales se marcan/desmarcan solo con periodo abierto. Sin reapertura.

### 4.9 Reserva (efecto lateral)
`pendiente_pago → confirmada` cuando existe al menos una CxC no cancelada y todas están pagadas (aplicación desde Finanzas o pago rápido en la reserva). La reversión posterior **no** devuelve la reserva a `pendiente_pago` (§12 P-09).

---

## 5. Reglas de negocio

Validación: **FR** = FormRequest; **P** = Policy/permiso; **S** = Service de dominio (transacción); **DB** = constraint; **J** = Job/Scheduler.

### 5.1 Transversales aplicadas
| ID | Regla | Dónde |
|---|---|---|
| RT-09 | Todo pago se asocia a una obligación: registrar exige CxC con saldo > 0 no cancelada; aplicar exige CxC de la misma reserva y moneda. Mensaje: "Regla transversal 9: el pago debe asociarse a una obligación" | S |
| RT-10 | Facturación con datos fiscales válidos (§5.7) | FR + S |
| RT-14 | Crédito B2B solo para agencias asociadas con línea vigente y estado `activa` | S |
| RT-15 | Moneda y TC conservados en CxC, pago, CxP, payout, comisión y comprobante; consolidación en PEN al TC del documento (fallback TC del día) | S + DB (CHECK fx en USD) |
| RT-16 | Acciones críticas auditadas (§9) | S |
| RT-17 | El backend valida permisos; sin permiso el botón se oculta y la acción responde 403 (prototipo: toast "Sin permiso para … requiere …") | P |
| RT-22 | Idempotencia en registrar/aplicar/revertir pago, pago a proveedor, emisión, liquidación, cálculo de comisiones | Middleware `Idempotency-Key` |
| RT-23 | Documentos vinculados al origen (comprobante → CxC → reserva; NC → comprobante) | DB |
| RT-24 | Sin borrado físico; comprobantes nunca se eliminan | DB |
| PRD-BR-007 | Proveedor inactivo no disponible: el modal "Registrar costo" solo lista proveedores `activo`; aprobar CxP de proveedor no activo muestra advertencia (no bloquea) | S |
| PRD-BR-014 | Modificación crítica auditada | S |

### 5.2 Cuentas por cobrar y anticipos (PROC-040/041)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-01 | Estado de CxC según §4.1; `paid_amount` = Σ aplicaciones vigentes de pagos `aplicado` (no se confía en valor enviado) | S + J |
| FIN-BR-02 | Anticipo solo para reservas no `cancelada`/`completada` | FR |
| FIN-BR-03 | Importe de anticipo > 0 y ≤ total de la reserva − Σ CxC no canceladas ("PROC-041: el anticipo excede el saldo no comprometido de la reserva") | S (con lock de reserva) |
| FIN-BR-04 | Defaults del modal: 50 % del total (tope: disponible), vencimiento hoy+3, concepto "Anticipo N% — {título}", moneda de la reserva, TC del día si USD, notificar = sí | FR (defaults UI) |
| FIN-BR-05 | Recordatorio: bloqueado si la CxC está pagada; envía email y WhatsApp si el cliente tiene teléfono; registra fecha | S |
| FIN-BR-06 | Aging en listado: Por vencer / 0–30 / 31–60 / >60 días sobre saldos abiertos en PEN; panel Finanzas usa 5 tramos (…/61–90/>90) — **unificar a 5 tramos** [Decisión] | S (query) |

### 5.3 Pagos, aplicación y reversión (PROC-042/043)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-07 | Monto > 0 y ≤ saldo de la CxC ("PROC-042: el monto … no puede exceder el saldo pendiente") | FR + S |
| FIN-BR-08 | Referencia/N° de operación obligatoria salvo métodos Efectivo ("Caja sede") y Crédito B2B ("Línea B2B") | FR |
| FIN-BR-09 | Pago en USD requiere TC (default TC del día) y se conserva | FR |
| FIN-BR-10 | Crédito B2B: el cliente debe ser una agencia (`b2b_agencies.client_id`), agencia `activa`, y monto en PEN ≤ `credit_limit − credit_used`; suma a `credit_used`; la reversión resta (mín. 0) | S + lock `b2b:credit:{agency}` |
| FIN-BR-11 | Registro back-office = confirmar + aplicar atómico (dos transiciones, un evento `PaymentApplied`) | S |
| FIN-BR-12 | Solo pagos `pendiente` se validan; el modal muestra si el abono figura en el extracto (línea con match) y advierte si no | S |
| FIN-BR-13 | Rechazo con motivo obligatorio; la CxC se mantiene; se notifica al cliente; reintento vuelve a `pendiente` e incrementa `retries` (Excepción "Pago rechazado" DOC-PROC-001 §24) | FR + S |
| FIN-BR-14 | Solo pagos `confirmado` se aplican; sin aplicación parcial ni reparto entre CxC; monto ≤ saldo de la CxC elegida | S |
| FIN-BR-15 | Tras aplicar: recalcular CxC y, si la reserva está `pendiente_pago` y todas sus CxC no canceladas están pagadas, pasarla a `confirmada` (auditoría "confirmada (obligaciones pagadas)") | S |
| FIN-BR-16 | Reversión: permiso `finance.payments.revert`, solo `confirmado`/`aplicado`, motivo obligatorio, desaplicar y recalcular; el modal muestra saldo antes/después | P + FR + S |
| FIN-BR-17 | Pagos `revertido`/`rechazado` no participan en conciliación ni en saldos | S |

### 5.4 Cuentas por pagar y costos (PROC-044/045/046)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-18 | Estado de CxP según §4.3 | S + J |
| FIN-BR-19 | Programar o pagar exige CxP aprobada ("PROC-046: Obligación → Aprobación → Pago") | S |
| FIN-BR-20 | Aprobación advierte (no bloquea) si el proveedor no está activo o la reserva no existe | S |
| FIN-BR-21 | Programación: fecha ≥ hoy (default: vencimiento, o hoy si vencida); cuenta de origen de la misma moneda | FR |
| FIN-BR-22 | Payout: monto > 0 y ≤ saldo; N° de operación obligatorio; TC obligatorio si USD; al quedar pagada se limpia la programación | FR + S |
| FIN-BR-23 | Registrar costo: reserva no `cancelada`/`borrador`; proveedor activo; servicio opcional (si se elige, precarga el proveedor del servicio); monto > 0; vence hoy+15 por defecto; nace `abierta` sin aprobar | FR + S |
| FIN-BR-24 | El costo solo impacta la rentabilidad al aprobarse; el modal muestra el impacto estimado en el margen (semáforo) | S |
| FIN-BR-25 | Panel Finanzas marca CxP "sin factura" si el proveedor no cargó su factura en la extranet; la extranet informa "Finanzas programa el pago solo con la factura registrada" — **no se bloquea en el prototipo** (§12 P-11) | S |

### 5.5 Comisiones (PROC-047)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-26 | Regla: reserva canal `B2B` con agencia vinculada al cliente → beneficiario agencia, `% = commission_pct` del convenio, base = total de la reserva. Si no: beneficiario = responsable de la reserva, 2,5 % si canal Corporativo/Grupos o cliente segmento Corporativo, si no 3 %; base = total/1,18 en PEN, total en USD ("exportación, sin IGV") | S (config en `fin_commission_rules`) |
| FIN-BR-27 | Se calculan para reservas en `confirmada, en_operacion, completada, pendiente_pago, pendiente_proveedor, parcial, reprogramada`; máximo una por (reserva, tipo) | S + DB unique |
| FIN-BR-28 | Aprobar solo `calculada`; pagar solo `aprobada`; forma de pago según tipo (vendedor: Planilla del mes / Transferencia; agencia: NC a cuenta B2B / Transferencia); referencia obligatoria | S + FR |
| FIN-BR-29 | Recalcular prohibido si `pagada`; si estaba `aprobada` vuelve a `calculada` y requiere nueva aprobación | S |
| FIN-BR-30 | El detalle alerta si el monto difiere de la regla vigente en > 0,05 | S |

### 5.6 Rentabilidad y liquidación (PROC-048)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-31 | Universo: reservas ≠ `borrador`/`cancelada` | S |
| FIN-BR-32 | Ingresos = total de la reserva (PEN al TC de la reserva); costo = Σ costo de servicios no cancelados + costos adicionales **aprobados**; margen bruto = ingresos − costo; utilidad neta = margen − Σ comisiones (todas); margen % = bruto/ingresos | S |
| FIN-BR-33 | Semáforo de margen: < 15 % rojo, 15–25 % ámbar, > 25 % verde (panel Finanzas: ≥ 25 % verde) — **unificar** [Decisión] | S |
| FIN-BR-34 | Margen por producto: Σ (precio − costo) de servicios con `product_id` | S |
| FIN-BR-35 | Datos de margen, costos y utilidad solo para `finance.profitability` / `catalog.products.view_profitability` / `quotes.approve_internal` (BRIEF_ROLES principio 2) | P |
| FIN-BR-36 | Liquidar requiere: ≥ 1 CxC y todas pagadas; todas las CxP de la reserva con saldo 0; costos adicionales aprobados; comisiones aprobadas o pagadas; reserva `completada`. Mensaje enumerando pendientes | S |
| FIN-BR-37 | Liquidación guarda snapshot (ingreso, costo, comisiones, utilidad, %) y notifica al responsable (`reserva.liquidada`, portal); no se re-liquida | S + DB unique |
| FIN-BR-38 | Estado de cuenta: email al cliente con saldo por cobrar; auditado | S |

### 5.7 Facturación electrónica (PROC-050)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-39 | Factura: documento RUC que cumpla `^(10\|15\|17\|20)\d{9}$` ("Factura requiere RUC válido de 11 dígitos (Regla transversal 10)") | FR |
| FIN-BR-40 | Boleta: DNI `^\d{8}$` o Pasaporte/CE `^[A-Z0-9]{6,12}$`; si el documento es RUC → error "corresponde emitir factura" | FR |
| FIN-BR-41 | NC: debe referenciar un comprobante `aceptado`, del mismo cliente y que no sea NC; documento del adquirente obligatorio; importe ≤ importe del referenciado | FR + S |
| FIN-BR-42 | Razón social/nombre obligatorio; descripción obligatoria | FR |
| FIN-BR-43 | Importe > 0 y ≤ máximo facturable de la CxC = importe CxC − Σ (comprobantes no anulados de esa CxC, NC con signo negativo; comprobantes antiguos sin CxC se imputan por reserva e importe igual) | S |
| FIN-BR-44 | Tipo por defecto según documento del cliente (RUC → Factura, otro → Boleta); importe por defecto = min(pagado, pendiente de facturar) si hay pagos, si no el pendiente | FR (defaults) |
| FIN-BR-45 | Domiciliado → operación gravada, IGV 18 % **incluido** (base = total/1,18); no domiciliado → exportación sin IGV. Default: domiciliado si país del cliente = Perú | S |
| FIN-BR-46 | Numeración: Factura `F001-` + 8 dígitos, Boleta `B001-` + 8 dígitos (series configurables); NC `FC01-` si modifica factura, `BC01-` si modifica boleta | S + `FOR UPDATE` |
| FIN-BR-47 | Solo se entregan comprobantes `aceptado` ("Solo se entregan comprobantes aceptados por el OSE"); NC solo desde `aceptado` | S |
| FIN-BR-48 | Periodo cerrado: no se emiten comprobantes con fecha del periodo; ajustes vía NC/ND en el periodo siguiente (texto del cierre; **no aplicado en el prototipo**, implementar) | S |
| FIN-BR-49 | La emisión es asíncrona: se persiste, se encola en outbox y la UI muestra "Esperando respuesta del OSE"; errores externos no revierten la transacción interna (PROC-065, RT-21) | S + J |
| FIN-BR-50 | "Cobrado por facturar" = por reserva, Σ pagos aplicados (PEN) − Σ comprobantes ≠ NC no anulados (PEN) > 0,50 | S |

### 5.8 Conciliación bancaria (PROC-049)
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-51 | Candidatos: abono (> 0) ↔ pagos no `revertido`/`rechazado` no vinculados a otra línea; cargo (< 0) ↔ CxP aprobadas o pagadas no vinculadas | S |
| FIN-BR-52 | Match: \|importe PEN del candidato − \|importe línea\|\| < 0,50; se prioriza el candidato cuyo N° (≥ 5 dígitos) de la descripción aparece en la referencia del pago o en el RUC del proveedor. Pagos USD al TC conservado del pago; CxP por saldo (o importe si pagada) | S |
| FIN-BR-53 | Confirmar: pago `pendiente` → `confirmado` (no aplica); CxP con saldo → payout `cargo_bancario` por min(\|cargo\|/TC, saldo) | S |
| FIN-BR-54 | Resolver excepción exige sustento; asignar requiere candidato; alternativa "otro ingreso" (abono) o "gasto/comisión bancaria" (cargo) sin afectar obligaciones | FR + S |
| FIN-BR-55 | Cerrar exige que todas las líneas abiertas estén `conciliado`; las líneas cerradas quedan bloqueadas; resumen abonos/cargos/neto | S |

### 5.9 Cierre contable de periodo
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-56 | 7 controles (§2.2); cerrar exige todos OK; mensaje lista los pendientes | S |
| FIN-BR-57 | Controles manuales: solo `finance.invoicing` y periodo abierto; registran usuario y fecha; se auditan al marcar y desmarcar | P + S |
| FIN-BR-58 | Al cerrar notifica a Finanzas (`contabilidad.cierre`, email) | J |

### 5.10 Permisos compuestos
| ID | Regla | Dónde |
|---|---|---|
| FIN-BR-59 | Toda acción operativa de tesorería (registrar/validar/aplicar/rechazar pagos, anticipos, recordatorios, programar/pagar CxP, registrar costos, calcular/recalcular/pagar comisiones, liquidar, estado de cuenta, conciliación) exige `finance.payments.register` además del permiso del recurso | P |

---

## 6. API REST

Prefijo `/api/v1/finance`. Todas requieren tenant autenticado. "Idem" = `Idempotency-Key` obligatorio; "Lock" = `lock_version`/`If-Match` del recurso indicado. Errores de negocio: `409` (estado) o `422` con `code` (`FIN-BR-xx`, `PROC-04x`, `RT-09`…).

### 6.1 Cuentas por cobrar
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/receivables` | `finance.receivables` | Listado; `filter[status]`, `filter[currency]`, `filter[client]`, `filter[reservation]`, `filter[due_from/to]`, `sort=due_date`; incluye saldo y saldo PEN | — |
| GET | `/receivables/summary` | `finance.receivables` | KPIs, aging (5 tramos), próximos vencimientos, "cobrado por facturar" | — |
| GET | `/receivables/{code}` | `finance.receivables` | Detalle + pagos + comprobantes de la reserva + auditoría + notificaciones | — |
| GET | `/reservations/{code}/account` | `finance.receivables` | Total, comprometido, disponible para anticipo | — |
| POST | `/receivables/advances` | `OP` | Solicitar anticipo `{reservation_id, pct?, amount, due_date, concept, notify}` | Idem; lock reserva |
| POST | `/receivables/{code}/reminders` | `OP` | Enviar recordatorio | Idem |

### 6.2 Pagos
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/payments` | `finance.receivables` o `OP` | Listado; filtros estado, método, reserva | — |
| GET | `/payments/{code}` | ídem | Detalle + historial + línea de conciliación | — |
| POST | `/payments` | `OP` | Registrar y aplicar `{receivable_id, method, paid_on, amount, fx_rate?, reference?, note?}` | Idem; lock CxC |
| POST | `/payments/{code}/validate` | `OP` | `{apply: bool, receivable_id?}` pendiente → confirmado [→ aplicado] | Idem; lock pago |
| POST | `/payments/{code}/reject` | `OP` | `{reason}` | lock pago |
| POST | `/payments/{code}/retry` | `OP` | rechazado → pendiente | lock pago |
| POST | `/payments/{code}/apply` | `OP` | `{receivable_id}` confirmado → aplicado | Idem; lock pago + CxC |
| POST | `/payments/{code}/revert` | `finance.payments.revert` | `{reason}` | Idem; lock pago + CxC |

### 6.3 Cuentas por pagar y costos
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/payables` | `finance.payables` | `filter[status]`, `filter[kind]=servicio\|costo_adicional`, proveedor, reserva | — |
| GET | `/payables/by-provider` | `finance.payables` | Totales, pagado, saldo, vencido, próximo vencimiento por proveedor (PEN) | — |
| GET | `/payables/summary` | `finance.payables` | KPIs | — |
| GET | `/payables/{code}` | `finance.payables` | Detalle + payouts + factura del proveedor + auditoría | — |
| POST | `/costs/preview` | `finance.payables` | Impacto en margen `{reservation_id, amount, currency}` | — |
| POST | `/costs` | `finance.payables` + `OP` | Registrar costo adicional | Idem |
| POST | `/payables/{code}/approve` | `finance.payables` | Aprobar (devuelve advertencias) | lock |
| POST | `/payables/{code}/schedule` | `finance.payables` + `OP` | `{scheduled_date, bank_account_id}` | lock |
| POST | `/payables/{code}/payouts` | `finance.payables` + `OP` | `{method, bank_account_id, amount, fx_rate?, paid_on, operation_ref}` | Idem; lock |
| GET | `/bank-accounts` | `finance.payables` o `OP` | Cuentas de origen (filtro moneda) | — |

### 6.4 Comisiones
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/commissions` | `finance.commissions` | Filtros estado, tipo, beneficiario | — |
| GET | `/commissions/summary` | `finance.commissions` | KPIs, por beneficiario, reservas sin cálculo | — |
| GET | `/commission-rules` | `finance.commissions` | Reglas vigentes + convenios B2B | — |
| GET | `/commissions/{code}` | `finance.commissions` | Detalle + diferencia vs regla vigente | — |
| POST | `/commissions/calculate` | `finance.commissions` + `OP` | Calcula pendientes (idempotente por reserva/tipo) | Idem |
| POST | `/commissions/{code}/approve` | `finance.commissions` | | lock |
| POST | `/commissions/{code}/recalculate` | `finance.commissions` + `OP` | | lock |
| POST | `/commissions/{code}/pay` | `finance.commissions` + `OP` | `{method, reference}` | Idem; lock |

### 6.5 Rentabilidad y liquidación
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/profitability` | `finance.profitability` | KPIs, series mensuales, por producto/vendedor/canal | — |
| GET | `/profitability/reservations` | `finance.profitability` | Tabla por reserva; `filter[light]=r\|a\|g`, `filter[channel]` | — |
| GET | `/reservations/{code}/settlement` | `finance.profitability` | Estado de resultados, flujo de caja, cobros, costos, comisiones, checklist | — |
| POST | `/reservations/{code}/settlement` | `finance.profitability` + `OP` | Cerrar liquidación | Idem; lock reserva |
| POST | `/reservations/{code}/account-statement` | `OP` | Enviar estado de cuenta | Idem |

### 6.6 Facturación
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/invoices` | `finance.invoicing` | Filtros tipo, estado, cliente, reserva, periodo | — |
| GET | `/invoices/summary` | `finance.invoicing` | KPIs + cobros pendientes de facturar | — |
| GET | `/invoices/{number}` | `finance.invoicing` | Detalle + totales + auditoría + eventos fiscales | — |
| GET | `/receivables/{code}/invoice-draft` | `finance.invoicing` | Prellenado (tipo, documento, nombre, domiciliado, máximo, descripción) | — |
| POST | `/invoices/validate` | `finance.invoicing` | Validación fiscal en seco (checklist de §5.7) | — |
| POST | `/invoices` | `finance.invoicing` | Emitir factura/boleta/NC `{receivable_id, doc_type, customer_*, domiciled, amount, fx_rate?, description, ref_invoice_id?, credit_note_reason_code?, credit_note_reason?}` → `202` con estado `pendiente_envio`/`emitido` | Idem |
| POST | `/invoices/{number}/status-check` | `finance.invoicing` | Consultar respuesta OSE | — |
| POST | `/invoices/{number}/resend` | `finance.invoicing` | Corregir (TC, descripción) y reenviar observado (sujeto a §12 P-03) | Idem; lock |
| POST | `/invoices/{number}/deliver` | `finance.invoicing` | Entregar/reenviar al cliente (email + portal) | Idem |
| GET | `/invoices/{number}/pdf` · `/xml` · `/cdr` | `finance.invoicing` (+ portal cliente para los suyos) | URL firmada; PDF generado por job | — |
| POST | `/invoices/{number}/void` | `finance.invoicing` | Comunicación de baja — **futuro, por validar** | Idem |
| POST | `/api/v1/integrations/fiscal/{provider}/webhook` | Firma del proveedor | Respuesta asíncrona del OSE/PSE (si el proveedor la ofrece) | Idem por ticket |

### 6.7 Conciliación
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/bank-lines` | `OP` | Filtros estado, abiertas/cerradas | — |
| POST | `/bank-statements` | `OP` | Multipart `{bank, bank_account_id?, file}` → job de normalización | Idem |
| POST | `/reconciliation/matching` | `OP` | Ejecuta matching sobre líneas pendientes | Idem |
| POST | `/bank-lines/{code}/confirm` | `OP` | Confirmar sugerencia | lock línea |
| POST | `/bank-lines/{code}/reject` | `OP` | Rechazar sugerencia | lock |
| POST | `/bank-lines/{code}/resolve` | `OP` | `{mode: assign\|other, target_id?, note}` | lock |
| GET/POST | `/reconciliation/closures` | `OP` | Listar / cerrar conciliación | Idem |

### 6.8 Paneles y cierre de periodo
| Método | Endpoint | Permiso | Descripción | Idem / Lock |
|---|---|---|---|---|
| GET | `/dashboards/finance` | `finance.receivables` + rol finance | Datos del panel §2.1 (con TC conservado) | — |
| GET | `/dashboards/accounting` | `finance.invoicing` | Datos del panel §2.2 | — |
| GET | `/period-closes/{period}` | `finance.invoicing` | Controles automáticos y manuales | — |
| PUT / DELETE | `/period-closes/{period}/checks/{check}` | `finance.invoicing` | Marcar / desmarcar control manual (periodo abierto) | lock periodo |
| POST | `/period-closes/{period}/close` | `finance.invoicing` | Cerrar periodo | Idem |

Exportación CSV de todas las tablas: endpoint genérico de exportación (PROC-061) con `reports.export`.

---

## 7. Permisos y comportamiento por rol

Alcance de datos: **todo el tenant** para los roles con acceso (Finanzas no aplica `Scope` de Ventas). Ventas, Operaciones, Guía, Conductor, Proveedor, B2B, Cliente, Pasajero y Super Admin **no ven** el grupo Finanzas (menú filtrado; URL → 403 auditado `acceso_denegado`). Los externos ven sus propios pagos/comprobantes solo en sus portales (otras specs).

### 7.1 Acceso a pantallas
| Pantalla | Tenant Admin | Manager | Finanzas | Contabilidad |
|---|---|---|---|---|
| Cuentas por cobrar | ✓ | ✓ solo lectura | ✓ | ✓ consulta + facturar |
| Pagos y aplicaciones | ✓ | ✓ solo lectura | ✓ | ✓ solo lectura |
| Cuentas por pagar | ✓ | ✓ + aprobar | ✓ | ✗ (403) |
| Comisiones | ✓ | ✓ + aprobar | ✓ | ✗ |
| Rentabilidad | ✓ | ✓ solo lectura | ✓ | ✗ |
| Facturación | ✓ | ⚠ en menú, contenido bloqueado (aviso `finance.invoicing`) | ✓ | ✓ |
| Conciliación | ✓ | ⚠ en menú, contenido bloqueado (aviso `finance.payments.register`) | ✓ | ✗ (403) |
| Panel propio | Ejecutivo | Gerencia | Finanzas (§2.1) | Contabilidad (§2.2) |

**[Decisión]** quitar `finance.profitability` de `ACCESS['finanzas/facturacion']` y `['finanzas/conciliacion']` para que Manager no vea menús inutilizables, o darle lectura explícita (§12 P-12).

### 7.2 Acciones
| Acción (botón) | Permiso | Admin | Manager | Finanzas | Contab. | Sin permiso |
|---|---|---|---|---|---|---|
| Solicitar anticipo, Registrar pago, Cobrar, Enviar recordatorio | `OP` | ✓ | ✗ | ✓ | ✗ | Botón oculto (`data-noperm`); columna "Acciones" de CxC y Pagos oculta |
| Validar / Rechazar / Reintentar / Aplicar pago | `OP` | ✓ | ✗ | ✓ | ✗ | Oculto; drawer sin acciones |
| Revertir pago | `finance.payments.revert` | ✓ | ✗ | ✓ | ✗ | Oculto; texto "su rol no puede revertir" en tarjeta PROC-043 |
| Emitir comprobante (CxC, Facturación, "Emitir" en pendientes) | `finance.invoicing` | ✓ | ✗ | ✓ | ✓ (botón primario; "Ir a facturación" en CxC) | Oculto |
| Consultar OSE, Corregir y reenviar, Nota de crédito, Entregar, Vista previa | `finance.invoicing` | ✓ | — | ✓ | ✓ | — |
| Aprobar CxP / costo | `finance.payables` | ✓ | ✓ | ✓ | — | Oculto |
| Registrar costo, Programar, Pagar CxP | `finance.payables` + `OP` | ✓ | ✗ | ✓ | — | Oculto |
| Calcular pendientes / Recalcular comisión | `finance.commissions` + `OP` | ✓ | ✗ | ✓ | — | Oculto |
| Aprobar comisión | `finance.commissions` | ✓ | ✓ | ✓ | — | — |
| Pagar comisión | `finance.commissions` + `OP` | ✓ | ✗ | ✓ | — | Oculto |
| Ver liquidación | `finance.profitability` | ✓ | ✓ (solo "Ver reserva"/"Cerrar") | ✓ | — | — |
| Cerrar liquidación, Enviar estado de cuenta | `finance.profitability` + `OP` | ✓ | ✗ | ✓ | — | No se muestran |
| Importar, Matching, Confirmar/Rechazar/Resolver, Cerrar conciliación | `OP` | ✓ | — | ✓ | — | — |
| Controles manuales y Cerrar periodo | `finance.invoicing` | (panel ejecutivo: no) | — | (panel finanzas: no) | ✓ | Checkbox deshabilitado / botón deshabilitado con *title* |
| Exportar CSV | `reports.export` (backend) | ✓ | ✓ | ✓ | ✓ | Prototipo: visible siempre |

Mensajes de rol (banner `roleNote`): Manager "Modo supervisión… solo lectura; puede aprobar cuentas por pagar y costos / comisiones"; Contabilidad "emisión, corrección y entrega de comprobantes" o "consulta de CxC y pagos para facturar; registrar, aplicar o revertir corresponde a Finanzas". Acceso por URL a una pantalla del módulo sin el permiso de contenido → aviso en página (no 403) citando la Regla transversal 17.

Datos sensibles: costos, márgenes y utilidad (Rentabilidad, liquidación, panel Finanzas "Margen por reserva", impacto del costo) solo con `finance.profitability`; Contabilidad no los ve (no accede a Rentabilidad ni CxP).

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio
| Evento | Origen | Listeners (cola) |
|---|---|---|
| `ReceivableCreated` (`kind`) | Anticipo, Reservas, Canales | Notificación `pago.solicitud` (notifications) |
| `PaymentRegistered` / `PaymentValidated` | Pagos | Auditoría; tablero en tiempo real (Reverb) |
| `PaymentApplied` | Registrar, aplicar, conciliación→aplicar | Recalcular CxC, sincronizar reserva (critical); `pago.recibido`; KPIs caja |
| `PaymentRejected` | Rechazo | `pago.rechazado` (notifications) |
| `PaymentReverted` | Reversión | Recalcular CxC, liberar crédito B2B (critical); `pago.revertido`; control "reversión sin NC" |
| `PayableApproved` / `CostApproved` | CxP | Recalcular rentabilidad (default) |
| `SupplierPaymentRegistered` | Payout | `pago.proveedor` (notifications); extranet del proveedor |
| `CommissionsCalculated` / `CommissionApproved` / `CommissionPaid` | Comisiones | `comision.pagada` |
| `ReservationSettled` | Liquidación | `reserva.liquidada` (portal, responsable) |
| `InvoiceRequested` | Emisión | Outbox → `SendInvoiceToFiscalProvider` (integrations) |
| `InvoiceAccepted` / `InvoiceObserved` / `InvoiceRejected` | Respuesta OSE | Actualiza estado; `GenerateInvoicePdf` (documents); alerta a Contabilidad si observado/rechazado |
| `InvoiceDelivered` | Entrega | `comprobante.emitido` (email + portal) |
| `BankStatementImported` | Conciliación | `NormalizeBankStatement` (default) |
| `ReconciliationClosed`, `AccountingPeriodClosed` | Cierres | `contabilidad.cierre` (email a Finanzas) |

### 8.2 Jobs y scheduler
| Job | Cola | Disparo | Notas |
|---|---|---|---|
| `SendInvoiceToFiscalProvider` | integrations | Outbox | `tries` 5, backoff exponencial, `ShouldBeUnique` por invoice; registra `fin_fiscal_events`; fallo definitivo → alerta (PROC-065) |
| `PollFiscalStatus` | integrations | Cada 5 min para `emitido` > N min **[por validar según proveedor]** | Reemplaza la simulación de 1,4 s y el botón manual |
| `GenerateInvoicePdf` / `GenerateAccountStatementPdf` | documents | Evento | `sys_documents` con hash |
| `NormalizeBankStatement` | default | Importación | Parser por banco (CSV/TXT/XLSX) **[por validar formatos]** |
| `fin:mark-overdue` | low | Diario 00:05 hora del tenant | CxC/CxP `vencida` |
| `fin:payment-reminders` | notifications | Diario (DOC-PROC-001 §26.3) | Prototipo: solo manual; política de días a definir |
| `fin:calculate-commissions` | default | Diario (opcional) | Prototipo: manual |
| `fin:import-fx-rate` | integrations | Diario | Fuente por validar (SBS/SUNAT) |
| `ExportJob` | reports | Exportar CSV | PROC-061 |

Locks Redis: `fin:receivable:{id}` (aplicar/revertir), `b2b:credit:{agency}` (crédito), `fin:series:{series}` (o `FOR UPDATE`), `fin:bankline:{id}`, `fin:settlement:{reservation}`.

### 8.3 Notificaciones (evento del prototipo → canal → destinatario)
| Evento | Canal(es) | Destinatario | Asunto (prototipo) |
|---|---|---|---|
| `pago.solicitud` | Email + Portal | Cliente | "Solicitud de anticipo CXC-… — monto, vence …" |
| `pago.recordatorio` | Email (+ WhatsApp si hay teléfono) | Cliente | "Recordatorio de pago CXC-… — saldo …, vence …" |
| `pago.recibido` | Email | Cliente | "Pago recibido PAG-… — monto (CXC-…)" |
| `pago.rechazado` | Email | Cliente | "Pago PAG-… no validado: motivo. La obligación sigue pendiente." |
| `pago.revertido` | Email | Cliente | "Reversión del pago PAG-… — monto" |
| `pago.proveedor` | Email | Proveedor | "Pago realizado CXP-… — monto · Op. …" |
| `comision.pagada` | Email | Vendedor / agencia | "Comisión COM-… pagada — monto" |
| `estado_cuenta.enviado` | Email | Cliente | "Estado de cuenta RES-… — saldo …" |
| `reserva.liquidada` | Portal | Responsable de la reserva | "Reserva RES-… liquidada — utilidad …" |
| `comprobante.emitido` | Email + Portal | Cliente | "Comprobante electrónico F001-… — monto" |
| `contabilidad.cierre` | Email | Finanzas | "Periodo … cerrado por …" |
| `proveedor.factura` (entrante, extranet) | Portal | Finanzas | "… registró la factura … para CXP-…" |

---

## 9. Auditoría

Registro con actor, tenant, entidad, acción, antes, después, fecha, IP, correlación (PROC-063). Acciones del prototipo:

| Entidad | Acciones (valor `action`) | Antes → después registrado |
|---|---|---|
| Pago | `registrar`, `validar`, `aplicar`, `rechazar`, `reintentar`, `revertir`, `registrar_aplicar` (Reservas) | estado + monto/TC, CxC destino, motivo |
| Cuenta por cobrar | `crear`, `crear_anticipo`, `actualizar_saldo`, `recalcular_saldo`, `recordatorio`, `cancelar` | pagado antes/después + estado; canales |
| Reserva | `cambio_estado` (pendiente_pago → confirmada), `liquidar`, `enviar_estado_cuenta` | estado; utilidad |
| Costo / Cuenta por pagar | `registrar`, `aprobar`, `programar_pago`, `pagar`, `factura_proveedor` (extranet) | monto, reserva/servicio, fecha y cuenta, pagado antes/después + N° op. |
| Rentabilidad | `impacto_costo` | +monto (CXP) |
| Comisión | `calcular`, `aprobar`, `recalcular`, `pagar` | monto, % y base antes/después, forma de pago |
| Comprobante / Nota de crédito | `emitir`, `respuesta_ose`, `reenviar`, `entregar`, `solicitar_pdf` | tipo, monto, CxC; estado OSE; TC |
| Conciliación | `importar`, `matching`, `confirmar_match`, `rechazar_match`, `resolver_excepcion`, `cerrar` | archivo/banco, conteos, destino del match, sustento, neto |
| Cierre contable | `control_ok`, `control_reabierto`, `cerrar_periodo` | control, periodo |
| Agencia B2B | `credito_excedido` (Canales) | disponible vs intento |
| Exportación | `exportar` | filas |
| Acceso | `acceso_denegado` | rol, permiso requerido |

Toda modificación de `fx_rate`, reversión, NC y cierre es "acción crítica" (no editable, retención según política del tenant).

---

## 10. Casos de prueba mínimos

### 10.1 Pest (feature)
| # | Caso |
|---|---|
| T01 | Aislamiento: usuario del tenant A no lista ni abre CxC/pagos/comprobantes del tenant B (RLS + scope) |
| T02 | RBAC: Manager `POST /payments` → 403; Contabilidad `POST /payables/{}/approve` → 403; Ventas `GET /receivables` → 403; Contabilidad `POST /invoices` → 201/202 |
| T03 | Permiso compuesto: rol con `finance.payables` sin `OP` no puede pagar CxP (403) pero sí aprobar |
| T04 | Registrar pago > saldo → 422 `PROC-042`; sin referencia (Transferencia) → 422; Efectivo sin referencia → OK |
| T05 | Registrar pago total en CxC única de reserva `pendiente_pago` → CxC `pagada`, reserva `confirmada`, auditoría 3 entradas |
| T06 | Crédito B2B: agencia bloqueada → 422 `RT-14`; crédito insuficiente → 422; OK suma `credit_used`; revertir resta |
| T07 | Máquina de estados de pago: aplicar `pendiente` → 409; revertir `rechazado` → 409; rechazar sin motivo → 422; reintento incrementa `retries` |
| T08 | Reversión sin `finance.payments.revert` → 403; con permiso recalcula `paid_amount` y estado |
| T09 | Idempotencia: dos `POST /payments` con misma `Idempotency-Key` → un solo pago; misma respuesta |
| T10 | Concurrencia: dos aplicaciones simultáneas que juntas exceden el saldo → una 409 (`lock_version`/lock Redis) |
| T11 | Anticipo > disponible no comprometido → 422 `PROC-041`; reserva cancelada → 422 |
| T12 | CxP no aprobada: programar/pagar → 422 `PROC-046`; programar con fecha pasada → 422 |
| T13 | Costo no aprobado no altera rentabilidad; aprobado sí (margen recalculado) |
| T14 | Comisiones: cálculo dos veces no duplica; reglas 3 % / 2,5 % / % convenio B2B y base sin IGV en PEN; recalcular pagada → 422; recalcular aprobada → `calculada` |
| T15 | Liquidación con checklist incompleto → 422 con pendientes; completa → snapshot; segunda vez → 409 |
| T16 | Fiscal: factura con DNI → 422 `RT-10`; boleta con RUC → 422; NC sin referencia o sobre comprobante no aceptado → 422; importe > máximo facturable → 422 |
| T17 | Numeración concurrente de series sin huecos ni duplicados (`FOR UPDATE`) |
| T18 | Outbox: fallo del adaptador fiscal no revierte la emisión; reintentos con backoff; fallo definitivo → alerta |
| T19 | Conciliación: matching por monto ±0,50 y referencia; pago revertido no es candidato; cerrar con excepciones → 422; línea cerrada inmutable |
| T20 | Periodo cerrado: emitir con fecha del periodo → 422 `FIN-BR-48`; marcar control sin permiso → 403 |
| T21 | Scheduler `fin:mark-overdue` marca CxC/CxP vencidas y no toca pagadas/canceladas |
| T22 | Consolidación: USD usa TC conservado del documento, no el TC del día |

### 10.2 Playwright (E2E)
| Caso | Recorrido |
|---|---|
| E2E-05 | Reserva → obligación (anticipo) → registrar pago → emitir comprobante → registrar costo → aprobar → rentabilidad actualizada → liquidación |
| E2E-09 | Cancelación de reserva → CxC canceladas → NC + reembolso pendiente → finanzas y auditoría |
| FIN-E2E-A | Finanzas valida pago pendiente con match bancario → confirmar y aplicar → CxC parcial/pagada |
| FIN-E2E-B | Contabilidad: panel → cobro sin comprobante → emitir boleta → aceptado → entregar → control del cierre en verde |
| FIN-E2E-C | Manager: ve CxC/pagos sin botones de acción; aprueba CxP y comisión; no ve "Pagar" |
| FIN-E2E-D | Conciliación completa: importar → matching → confirmar/resolver → cerrar |

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Tema | DOC-PROC-001 / Spec inicial | Prototipo / esta spec | Motivo |
|---|---|---|---|---|---|
| C01 | Agregado | Estado `rechazado` de pago + reintento | §5 Pago: Pendiente → Confirmado → Aplicado → Revertido | Añade `rechazado` y `rechazado → pendiente` | Implementa la excepción "Pago rechazado" (§24): mantener obligación, notificar, reintentar |
| C02 | Precisado | Reversión | PROC-043 genérico | Permitida desde `confirmado` y `aplicado`; motivo obligatorio; permiso `finance.payments.revert`; devuelve crédito B2B | Control de riesgo; trazabilidad |
| C03 | Precisado | Registro de pago en back-office | Recibir → … → Aplicar (pasos separados) | Un solo paso "Registrar y aplicar" (confirmado + aplicado); validación separada solo para pagos `pendiente` de otros canales | Operativa real de tesorería: el pago lo registra quien ya lo verificó |
| C04 | Precisado | Aplicación | "Aplicaciones" sin detalle | 1 pago → 1 CxC, misma reserva y moneda, sin aplicación parcial; tabla `fin_payment_applications` preparada para N:M | Simplicidad MVP con modelo extensible |
| C05 | Agregado | Sincronización con reserva | No descrito | `pendiente_pago → confirmada` al pagar todas las CxC | Evita confirmación manual |
| C06 | Cambiado | Estados de CxP | Abierta → Parcial → Pagada; vencida/cancelada | Prototipo: Abierta → Aprobada → Pagada; Vencida derivada; aprobación como atributo; sin Parcial ni Cancelada. **Spec: estados `abierta, aprobada, parcial, pagada, vencida, cancelada`** | PROC-044/046 exigen aprobación previa al pago; se recuperan Parcial/Cancelada de la doc inicial |
| C07 | Precisado | Costos (PROC-045) | Proceso sin entidad | El costo es una CxP `kind=costo_adicional` con tipo, servicio, documento y sustento; impacta rentabilidad solo aprobado | Un solo circuito de pago a proveedores |
| C08 | Agregado | Programación de pagos a proveedores | No existe | Fecha + cuenta de origen; KPI de programados a 7 días | Planificación de caja |
| C09 | Agregado | Crédito B2B como medio de pago | Regla 14 general | Método "Crédito B2B" con validación de línea y estado de agencia | Consistencia con portal B2B |
| C10 | Precisado | Reglas de comisión | "Regla" sin definir | 3 % directo/e-commerce, 2,5 % corporativo/grupos sobre neto sin IGV; B2B % de convenio sobre total; una por reserva/tipo; recálculo | Supuestos de negocio a validar (§12) |
| C11 | Precisado | Rentabilidad | Ingresos → Costos → Comisiones → Margen | Fórmulas FIN-BR-32, semáforo 15/25 %, cortes por producto/vendedor/canal | Coincide con spec de Productos §26 (utilidad y margen) |
| C12 | Agregado | Liquidación / cierre de reserva | Paso final del §15 sin proceso | Checklist de 5 condiciones + snapshot + estado de cuenta | Materializa "LIQUIDACIÓN / CIERRE" |
| C13 | Agregado | Facturación: tipos, validación fiscal, NC, series, entrega | PROC-050 sin detalle; "no declarar integración concreta" | Factura/Boleta/NC, reglas RUC/DNI/pasaporte, exportación sin IGV, series F001/B001/FC01/BC01, entrega email+portal, OSE simulado con aviso visible | Cumplir Regla 10 manteniendo la integración "por validar" |
| C14 | Agregado | Estados del comprobante | No definidos | Prototipo: emitido, aceptado, observado (anulado dibujado). Spec: + `pendiente_envio`, `rechazado`, `anulado` | Alinear con respuestas CDR reales |
| C15 | Precisado | Conciliación (PROC-049) | Pasos genéricos | Reglas de matching ±0,50 PEN + referencia/RUC; resoluciones "otro ingreso"/"gasto bancario"; cierre bloqueante | Automatizar con control humano |
| C16 | Agregado | Cierre contable de periodo | "Accounting: procesos contables definidos" | 7 controles, cierre del mes, bloqueo de emisión en periodo cerrado | Da contenido al rol Contabilidad |
| C17 | Agregado | Permiso compuesto `OP` | Permisos por módulo | Acciones operativas exigen `finance.payments.register` + permiso del recurso; Manager supervisa y aprueba; Contabilidad factura | Segregación de funciones (quien aprueba ≠ quien paga) |
| C18 | Agregado | Paneles Finanzas y Contabilidad | "Dashboard" genérico | Contenido de §2.1/§2.2 | Home por rol |
| C19 | Cambiado | Cálculos de los paneles | — | El prototipo usa TC del día y `status` almacenado en el panel, y TC conservado/estado derivado en el módulo. **Spec: una sola fuente (API) con TC conservado y estado derivado** | Evitar cifras distintas entre panel y módulo |
| C20 | Precisado | Recordatorios de pago | §26.3 "Recordar pagos" (scheduler) | Prototipo manual; spec: manual + job diario | Ambos requeridos |
| C21 | Agregado | Tipo de cambio diario | Regla 15 | Tabla `fin_fx_rates` (prototipo: `settings.fx` único) | Conservar TC por fecha |
| C22 | Cambiado | Menú de Manager | — | Prototipo muestra Facturación y Conciliación a Manager sin contenido usable; spec recomienda ocultarlas | Principio 1 de BRIEF_ROLES |

---

## 12. Supuestos y puntos a validar con negocio

| ID | Punto | Impacto | Propuesta |
|---|---|---|---|
| P-01 | **Exportación de servicios**: el prototipo trata como exportación sin IGV toda venta a cliente con país ≠ Perú y permite boleta con pasaporte (ej. B001-00008811 Keller). Según Ley del IGV solo ciertos servicios califican y en exportación corresponde factura | Riesgo tributario alto | Validar con contador: qué servicios/segmentos califican (hospedaje, paquete a agencia no domiciliada), documentos exigidos (pasaporte, TAM, ≤ 60 días), tipo de comprobante y código de operación. Hasta entonces: switch "no domiciliado" solo con rol Contabilidad y con alerta |
| P-02 | Proveedor OSE/PSE, contrato, API, ambiente de pruebas, costos por comprobante, límites y SLA | Bloquea salida a producción de PROC-050 | Seleccionar proveedor; construir adaptador tras interfaz `FiscalProvider`; mantener modo "simulado" por tenant hasta homologar |
| P-03 | "Corregir y reenviar" un comprobante observado con el mismo número | Un observado ya es válido ante SUNAT; un rechazado no puede reusar número según proveedor | Confirmar con el OSE; probable: observado → NC + nuevo comprobante; rechazado → nuevo número |
| P-04 | Motivos de NC (Catálogo 09), NC parciales acumuladas, Nota de débito | NC hoy solo por importe ≤ original, sin control acumulado | Agregar código de motivo y tope acumulado; definir si se requiere ND |
| P-05 | Facturación de anticipos y deducción en la factura final; forma de pago "Crédito" con cuotas en CxC a crédito (ej. corporativo 30 d) | Obligaciones de forma del UBL | Definir con contador; modelar cuotas desde las CxC |
| P-06 | Detracciones/percepciones aplicables a servicios turísticos | Posible obligación adicional | Validar con contador |
| P-07 | Margen calculado sobre total **con IGV** en reservas PEN, mientras la comisión usa base sin IGV | Rentabilidad sobrestimada en ventas gravadas | Definir si ingresos de rentabilidad son netos de IGV (recomendado) |
| P-08 | Porcentajes de comisión fijos (3 % / 2,5 %) y base USD = total | Reglas de negocio | Confirmar tabla de reglas y si son configurables por vendedor/meta |
| P-09 | Reversión de pago no devuelve la reserva a `pendiente_pago` ni exige NC | Inconsistencia de estado | Definir efecto en reserva y si la reversión dispara NC automática (el cierre contable ya lo controla) |
| P-10 | Cancelación de reserva (módulo Reservas) numera la NC con la serie de **Factura** (`Seq.next('Factura')`) y la deja `emitido` sin pasar por OSE; semilla contiene NC `F001-00001483` | Numeración inválida | Usar el servicio de emisión de este módulo (serie FC01/BC01, validación fiscal, outbox) |
| P-11 | ¿Bloquear pago a proveedor sin factura del proveedor registrada? | Control documental | Recomendado: bloquear programar/pagar sin factura salvo tipos prepago |
| P-12 | Acceso de Manager a Facturación y Conciliación (menú visible, contenido bloqueado) | UX/seguridad | Ocultar o dar lectura |
| P-13 | Cierre de periodo: ¿quién puede reabrir?; ¿los controles automáticos bloquean por movimientos de otros meses? (hoy "conciliación sin partidas abiertas" y "cobros sin comprobante" no filtran por periodo) | Cierre imposible por datos de otros meses | Filtrar controles por periodo; definir reapertura con doble aprobación |
| P-14 | Periodo de conciliación fijo ("Setiembre 2026") y cierre global (todas las líneas abiertas de todos los bancos) | Funcionalidad | Cierre por cuenta bancaria y periodo |
| P-15 | Formatos de extracto por banco (BCP, Interbank, BBVA) y posibilidad de conexión directa | Parser | Obtener layouts reales |
| P-16 | Fuente y momento del TC (SBS venta/compra, SUNAT, fecha de pago vs emisión); edición manual del TC en pago/emisión | Regla 15 | Definir fuente y quién puede sobrescribir |
| P-17 | Pago en moneda distinta a la CxC (cliente paga en PEN una CxC USD) | Hoy no permitido | Definir si se admite con TC y diferencia de cambio |
| P-18 | Liquidación: ¿se puede reabrir si aparece un costo posterior? | Integridad | Proponer ajuste en periodo siguiente, sin reabrir |
| P-19 | Tesorería (caja y bancos, `finance.treasury`) no está prototipada | Alcance | Especificar aparte |
| P-20 | Comisiones de reservas B2B creadas por el portal (Canales) nacen `calculada` sin pasar por la regla del módulo | Doble fuente de regla | Centralizar el cálculo en `fin_commission_rules` |