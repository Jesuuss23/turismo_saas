# CRM — Leads, oportunidades, actividades, Clientes 360, postventa y fidelización

Fuente: `proto/assets/js/modules/crm.js` (completo), `dashboard.js` (`salesPanel`, y los datos CRM de `managerBlock` como referencia), `core.js` (`STATUS`, `ACCESS`, `Scope`, `Audit`, `Notify`), `data.js` (`clients`, `leads`, `opportunities`, `stages`, `activities`, `rolePerms`, `users`) y la sincronización de etapas que hace `ventas.js`. Referencias: DOC-PROC-001 v1.0 (PROC-004 a PROC-010, reglas transversales RT-n) y Especificación PRD (Productos Turísticos).

Convenciones de esta especificación: `RT-n` = regla transversal n de DOC-PROC-001 §30. `CRM-BR-xx` = regla nueva de este módulo. "Supervisor" = roles `tenant_admin` y `manager` (en el prototipo, `isSup()`). "Ventas" = rol `sales` (`Scope.ownOnly()`).

---

## 1. Propósito y procesos cubiertos (PROC-xxx)

| Proceso | Qué cubre el módulo | Pantalla |
|---|---|---|
| PROC-004 Captación de lead | Registro manual/sistema, validación de contacto, detección de duplicados contra leads **y** clientes (correo/teléfono), cola comercial sin asignar, toma ("Tomar lead"), asignación/reasignación por supervisor, primer contacto | `crm/leads` |
| PROC-005 Calificación y conversión | Calificación BANT (score 0–100, umbral 50), cambio de estado controlado, conversión en una transacción a Cliente (nuevo o existente) + Oportunidad (+ reunión opcional), conservando el lead y su historial | `crm/leads/{id}` |
| PROC-006 Oportunidad | Pipeline de 6 etapas (kanban y tabla), probabilidad por etapa, ponderado, ganar/perder (motivo obligatorio), reabrir, crear cotización desde la oportunidad, sincronización automática de etapa desde Cotizaciones | `crm/oportunidades` |
| PROC-007 Actividades | Agenda (lista agrupada y semana), creación vinculada a lead/oportunidad/cliente/cotización, completar con resultado obligatorio + próxima actividad, reprogramar, reasignar, vencimiento | `crm/actividades` + bloques en todas las fichas |
| PROC-008 Customer 360 | Lista de clientes con agregados; ficha 360 con 11 pestañas (resumen, contactos, leads, oportunidades, cotizaciones, reservas, pasajeros, pagos, documentos, comunicaciones, actividades, historial) | `crm/clientes` |
| PROC-009 Postventa | Viajes por cerrar, encuesta NPS, caso automático por detractor (NPS ≤ 6), ciclo de reclamos/feedback | `crm/postventa` |
| PROC-010 Fidelización | Segmentación (segmento, recencia, destino), oportunidad de recompra con propuesta sugerida | `crm/postventa` |
| (Panel) | Panel de Ventas "Mi cartera" (KPIs, pipeline, agenda, cotizaciones por vencer, leads y bolsa común, próximos viajes, comisión estimada) | `dashboard` (rol `sales`) |

Fuera de alcance de este documento (aparecen en el menú CRM pero no están en `crm.js`): `crm/metas` (Metas comerciales) y `crm/reclamaciones` / `libro-reclamaciones` (Libro de reclamaciones INDECOPI). Ver §12.

---

## 2. Pantallas

| Ruta prototipo | Ruta Next.js | Qué hace | Roles con acceso |
|---|---|---|---|
| `#/crm` | `/crm` → redirect `/crm/leads` | Redirección | según `/crm/leads` |
| `#/crm/leads` | `/crm/leads` | KPIs (nuevos hoy, sin contactar, calificados, tasa de conversión), tabla con búsqueda/filtros (estado, fuente, asignación), gráficos por fuente y por estado, aviso de cola sin asignar (Ventas), filtro "Asesor" (supervisor) | `crm.leads.view`: Tenant Admin, Manager, Ventas |
| `#/crm/leads/nuevo` | `/crm/leads/new` | Lista + modal "Nuevo lead" | idem + `crm.leads.manage` para el modal |
| `#/crm/leads/{code}` | `/crm/leads/[code]` | Ficha: banner de estado, flujo de 8 pasos, ficha, BANT, actividades, historial, panel de acciones, responsable, duplicados, resultado de conversión | idem; Ventas: propio o sin asignar (si no → pantalla "Registro fuera de su cartera") |
| `#/crm/oportunidades` | `/crm/oportunidades?view=kanban\|tabla` | KPIs (pipeline, ponderado, ganadas del mes, tasa de cierre), kanban drag & drop / menú ⋯ en móvil, tabla con totales | Ruta: `crm.opportunities.manage` (Tenant Admin, Manager, Ventas) |
| `#/crm/oportunidades/nueva` | `/crm/oportunidades/new` | Lista + modal nueva oportunidad | idem |
| `#/crm/oportunidades/{code}` | `/crm/oportunidades/[code]` | Flujo de etapas + botones "Mover a", datos, cotizaciones vinculadas, actividades (propias + lead + cotizaciones), historial, resumen, cliente | idem; Ventas solo propias |
| `#/crm/actividades` | `/crm/actividades?view=lista\|semana` | KPIs (vencidas, hoy, 7 días, completadas 7 días), filtro tipo, lista agrupada (Vencidas/Hoy/Próximas/Completadas) o calendario semanal (lun–dom) con navegación; drawer de actividad | Ruta: `crm.activities.manage` (Tenant Admin, Manager, Ventas) |
| `#/crm/clientes` | `/crm/clientes` | KPIs (clientes, corporativo+B2B, VIP+grupos, NPS), tabla con agregados (reservas, total comprado, saldo, NPS, última compra) | `crm.clients.manage` o `finance.receivables`: Tenant Admin, Manager, Ventas, Finanzas, Contabilidad (estos dos solo lectura) |
| `#/crm/clientes/nuevo` | `/crm/clientes/new` | Lista + modal cliente | `crm.clients.manage` |
| `#/crm/clientes/{code}` | `/crm/clientes/[code]?tab=` | Customer 360: cabecera, alertas (saldo vencido, caso abierto, vista parcial), 5 KPIs, 12 pestañas | idem lista; Ventas solo su cartera de clientes (`Scope.clientIds`) |
| `#/crm/postventa` | `/crm/postventa` | KPIs (viajes por cerrar, NPS, promedio, casos abiertos), viajes recientes con encuesta/llamada, distribución NPS (donut + barras 0–10), tabla de casos, tabla de segmentación con recompra, últimas encuestas; drawer de caso | Ruta: `crm.clients.manage` (Tenant Admin, Manager, Ventas) |
| `#/dashboard` (rol `sales`) | `/dashboard` | Panel de Ventas (ver §7.4) | Ventas |

Componentes transversales usados: modal, drawer, `dataTable` (búsqueda, filtros, orden, paginación, exportación CSV auditada), `flow` (stepper), `timeline`, kanban. Las preferencias de vista (`crm_oppView`, `crm_actView`) se recuerdan por navegador; el filtro "Asesor" se guarda por sesión (`qh_advisor`) y es compartido con el módulo Ventas.

---

## 3. Modelo de datos PostgreSQL

Todas las tablas: RLS `tenant_isolation`, `created_by`, `updated_by`, `created_at`, `updated_at` (se omiten abajo salvo que tengan una regla). `code` generado por `sys_sequences` (`SELECT … FOR UPDATE`), formato del prototipo: prefijo + correlativo de 4 dígitos (`LEAD-0232`, `OPP-0122`, `ACT-0513`, `CLI-0011`, `ENC-0102`, `REC-0015`). Ningún registro CRM se borra físicamente (RT-24): no hay DELETE en la API.

### 3.1 `crm_catalog_items` (catálogos configurables del CRM)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK `tenants` |
| kind | varchar(30) | no | CHECK IN (`lead_source`,`lead_channel`,`lead_interest`,`client_segment`,`lost_reason`,`discard_reason`,`case_type`,`survey_channel`,`activity_type`) |
| code | varchar(40) | no | UNIQUE (tenant_id, kind, code) |
| label | varchar(120) | no | |
| requires_detail | boolean | no | default false. `true` para "Otro" en `lost_reason` (CRM-BR-24) |
| sort_order | smallint | no | default 0 |
| is_active | boolean | no | default true |

Semilla (valores del prototipo):
- `lead_source`: Formulario web, WhatsApp, Facebook Ads, Instagram, Referido, Feria Turismo, Llamada entrante, B2B, Email, Visita en oficina.
- `lead_channel`: Web, WhatsApp, Facebook, Instagram, Teléfono, Presencial, Email.
- `lead_interest`: Paquete, Tour, Excursión, Viaje corporativo, Viaje de promoción, Luna de miel, Alojamiento, Alianza.
- `client_segment`: Retail, Corporativo, VIP, Grupos, B2B.
- `lost_reason`: Precio, Eligió a la competencia, Sin presupuesto, Cambio de planes del cliente, Sin respuesta del cliente, Producto o fecha sin disponibilidad, Otro (`requires_detail`).
- `discard_reason`: No es cliente potencial (proveedor / alianza), Datos de contacto inválidos, Sin interés real, Fuera de cobertura / destino no ofrecido, Spam o prueba, Otro.
- `case_type`: Reclamo, Queja, Sugerencia, Felicitación.
- `survey_channel`: Llamada, WhatsApp, Email, Encuesta web, Portal del cliente.
- `activity_type`: Llamada, Email, WhatsApp, Reunión, Tarea, Postventa (código fijo: la lógica de primer contacto depende de él, ver CRM-BR-30).

### 3.2 `crm_leads`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | FK `tenants` |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| full_name | varchar(160) | no | |
| company | varchar(160) | sí | |
| email | varchar(190) | sí | |
| email_norm | varchar(190) | sí | `lower(trim(email))`, generada STORED. Índice (tenant_id, email_norm) |
| phone | varchar(40) | sí | |
| phone_norm | varchar(9) | sí | últimos 9 dígitos si hay ≥ 7 dígitos; si no, NULL (CRM-BR-03). Índice (tenant_id, phone_norm) |
| channel_code | varchar(40) | no | catálogo `lead_channel` |
| source_code | varchar(40) | no | catálogo `lead_source` |
| interest_code | varchar(40) | no | catálogo `lead_interest` |
| destination_id | uuid | sí | FK `catalog_destinations` (tipos Departamento, Ciudad, Zona, Lugar, Atracción) |
| destination_label | varchar(120) | sí | texto mostrado; `Varios` o texto histórico sin FK |
| product_id | uuid | sí | FK `catalog_products`; solo `ACTIVE`/`APPROVED` al asignar (CRM-BR-06) |
| travel_date | date | sí | ≥ hoy al registrar/editar (CRM-BR-05) |
| pax | smallint | no | default 0, CHECK ≥ 0 |
| status | varchar(30) | no | CHECK IN (`nuevo`,`contactado`,`calificado`,`convertido`,`duplicado`,`descartado`) |
| owner_id | uuid | sí | FK `iam_users`. NULL = cola comercial |
| score | smallint | no | default 0, CHECK 0–100 |
| notes | text | sí | |
| duplicate_of_type | varchar(10) | sí | CHECK IN (`lead`,`client`) |
| duplicate_of_id | uuid | sí | lead o cliente (polimórfico) |
| validated_at | timestamptz | no | momento de la validación PROC-004 |
| assigned_at | timestamptz | sí | |
| taken_by | uuid | sí | FK `iam_users`, cuando se autoasignó con "Tomar lead" |
| first_contact_at | timestamptz | sí | |
| discard_reason_code | varchar(40) | sí | catálogo `discard_reason` |
| discard_detail | text | sí | |
| discarded_at | timestamptz | sí | |
| discarded_at_step | smallint | sí | paso del flujo (0–7) en que se descartó (para el stepper) |
| converted_client_id | uuid | sí | FK `crm_clients` |
| converted_opportunity_id | uuid | sí | FK `crm_opportunities` |
| converted_at | timestamptz | sí | |
| converted_by | uuid | sí | FK `iam_users` |
| lock_version | integer | no | default 0 (toma concurrente, CRM-BR-08) |
| created_by | uuid | sí | NULL = Sistema (ingreso automático) |
| deleted_at | timestamptz | sí | no expuesto en API |

Restricciones: `CHECK (email IS NOT NULL OR phone IS NOT NULL)`; `CHECK (status <> 'convertido' OR (converted_client_id IS NOT NULL AND converted_opportunity_id IS NOT NULL))`; `CHECK (status <> 'descartado' OR discard_reason_code IS NOT NULL)`; `CHECK ((duplicate_of_type IS NULL) = (duplicate_of_id IS NULL))`; `CHECK (status IN ('nuevo') OR owner_id IS NOT NULL OR status IN ('duplicado','descartado'))` (CRM-BR-11). Índices: (tenant_id, status), (tenant_id, owner_id), (tenant_id, created_at DESC), (tenant_id, source_code), parcial `(tenant_id, created_at) WHERE owner_id IS NULL AND status IN ('nuevo','contactado')` (cola).

### 3.3 `crm_lead_qualifications` (historial BANT; el vigente es el último)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| lead_id | uuid | no | FK `crm_leads`, índice |
| budget | smallint | no | CHECK IN (0,10,18,25) |
| authority | smallint | no | CHECK IN (0,10,18,25) |
| need | smallint | no | CHECK IN (0,10,18,25) |
| timeline | smallint | no | CHECK IN (0,10,18,25) |
| score | smallint | no | GENERATED (budget+authority+need+timeline) STORED |
| declared_amount | numeric(14,2) | sí | CHECK ≥ 0 |
| currency | char(3) | sí | CHECK IN (`PEN`,`USD`) |
| notes | text | sí | |
| qualified_by | uuid | no | FK `iam_users` |
| qualified_at | timestamptz | no | |

Etiquetas por nivel (UI): Presupuesto {0 No definido, 10 Referencial/bajo, 18 Definido y acorde, 25 Aprobado y disponible}; Autoridad {Desconocida, Influenciador, Co-decisor, Decisor final}; Necesidad {Exploratoria, Interés general, Necesidad concreta, Urgente y específica}; Plazo {Sin fecha, > 6 meses, 1–6 meses, < 30 días}.

### 3.4 `crm_clients`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| kind | varchar(10) | no | CHECK IN (`persona`,`empresa`) |
| name | varchar(200) | no | nombre o razón social |
| doc_type | varchar(10) | no | CHECK IN (`DNI`,`RUC`,`PASAPORTE`,`CE`) |
| doc_number | varchar(20) | no | sin espacios. UNIQUE (tenant_id, doc_type, doc_number) WHERE deleted_at IS NULL (CRM-BR-16) |
| email / email_norm | varchar(190) | sí | idem leads, índice |
| phone / phone_norm | varchar(40)/varchar(9) | sí | idem leads, índice |
| country | varchar(80) | no | default `Perú` (recomendado migrar a `country_code char(2)`) |
| city | varchar(80) | sí | |
| birth_date | date | sí | solo personas |
| segment_code | varchar(40) | no | catálogo `client_segment` |
| owner_id | uuid | no | FK `iam_users` (responsable de cartera) |
| tags | text[] | no | default `{}`; índice GIN |
| nps_last | smallint | sí | CHECK 0–10; último puntaje registrado (CRM-BR-40) |
| source_lead_id | uuid | sí | FK `crm_leads` |
| b2b_agency_id | uuid | sí | FK `channel_b2b_agencies` (ej. CLI-0007 ↔ AGB-01) |
| deleted_at | timestamptz | sí | |

Restricciones: `CHECK (email IS NOT NULL OR phone IS NOT NULL)` (CRM-BR-17); `CHECK (kind <> 'empresa' OR country <> 'Perú' OR doc_type = 'RUC')` (RT-10); índices (tenant_id, owner_id), (tenant_id, segment_code), (tenant_id, created_at DESC). El vínculo con usuarios de portal está en `iam_users.client_id` (módulo IAM).

### 3.5 `crm_client_contacts`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| client_id | uuid | no | FK `crm_clients`, índice |
| name | varchar(160) | no | |
| role | varchar(120) | sí | cargo/relación; "Contacto (desde LEAD-xxxx)" al convertir |
| email | varchar(190) | sí | |
| phone | varchar(40) | sí | |
| is_primary | boolean | no | UNIQUE parcial (client_id) WHERE is_primary. El primero agregado es principal |
| source_lead_id | uuid | sí | FK `crm_leads` |

`contactName` del prototipo = nombre del contacto principal (vista/accessor, no columna).

### 3.6 `crm_pipeline_stages`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code). CHECK IN (`calificacion`,`descubrimiento`,`cotizacion`,`negociacion`,`ganada`,`perdida`) |
| name | varchar(60) | no | |
| default_probability | smallint | no | CHECK 0–100. Semilla: 20, 35, 55, 70, 100, 0 |
| sort_order | smallint | no | |
| is_closed | boolean | no | true para `ganada`/`perdida` |

### 3.7 `crm_opportunities`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| client_id | uuid | no | FK `crm_clients`, índice |
| title | varchar(200) | no | |
| stage | varchar(30) | no | CHECK IN (6 códigos). Transiciones solo vía servicio |
| amount | numeric(14,2) | no | CHECK ≥ 0 (> 0 en alta/edición, CRM-BR-20) |
| currency | char(3) | no | CHECK IN (`PEN`,`USD`) |
| probability | smallint | no | CHECK 0–100 |
| expected_close_date | date | no | |
| closed_at | date | sí | |
| owner_id | uuid | no | FK `iam_users` |
| destination_label | varchar(120) | sí | texto libre (el prototipo no usa FK) |
| pax | smallint | no | default 0, CHECK ≥ 0 |
| lead_id | uuid | sí | FK `crm_leads`; UNIQUE parcial (tenant_id, lead_id) WHERE lead_id IS NOT NULL |
| product_id | uuid | sí | FK `catalog_products` (heredado del lead) |
| origin | varchar(40) | sí | `Conversión de lead`, `Fidelización`, `Manual`… |
| notes | text | sí | necesidad/notas |
| lost_reason_code | varchar(40) | sí | catálogo `lost_reason` |
| lost_detail | text | sí | |
| lost_at_stage | varchar(30) | sí | etapa desde la que se perdió (para reabrir) |
| lock_version | integer | no | default 0 |
| deleted_at | timestamptz | sí | |

Restricciones: `CHECK (stage <> 'perdida' OR (lost_reason_code IS NOT NULL AND probability = 0 AND closed_at IS NOT NULL))`; `CHECK (stage <> 'ganada' OR (probability = 100 AND closed_at IS NOT NULL))`; `CHECK (stage IN ('ganada','perdida') OR closed_at IS NULL)`. Índices (tenant_id, stage), (tenant_id, owner_id), (tenant_id, expected_close_date), (tenant_id, created_at DESC). La FK inversa `quote_quotes.opportunity_id` se define en la especificación de Cotizaciones.

### 3.8 `crm_activities`
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| type | varchar(20) | no | CHECK IN (`llamada`,`email`,`whatsapp`,`reunion`,`tarea`,`postventa`) |
| subject | varchar(200) | no | |
| description | text | sí | "Descripción / guion" |
| related_type | varchar(20) | sí | CHECK IN (`lead`,`opportunity`,`client`,`quote`,`reservation`) |
| related_id | uuid | sí | polimórfico; `CHECK ((related_type IS NULL) = (related_id IS NULL))` |
| owner_id | uuid | no | FK `iam_users` |
| due_at | timestamptz | no | |
| status | varchar(20) | no | CHECK IN (`pendiente`,`vencida`,`completada`) |
| result | text | sí | `CHECK (status <> 'completada' OR result IS NOT NULL)` |
| completed_at | timestamptz | sí | |
| completed_by | uuid | sí | FK `iam_users` |
| auto_closed | boolean | no | default false (cierre automático al descartar lead, CRM-BR-14) |
| previous_activity_id | uuid | sí | FK self (cadena de seguimiento) |
| merged_from_lead_id | uuid | sí | FK `crm_leads` (reasignada por fusión) |

Índices: (tenant_id, owner_id, status, due_at), (tenant_id, related_type, related_id), (tenant_id, due_at) WHERE status <> 'completada'.

### 3.9 `crm_surveys` (encuestas NPS)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| client_id | uuid | no | FK `crm_clients`, índice |
| reservation_id | uuid | sí | FK `booking_reservations`; UNIQUE parcial (tenant_id, reservation_id) WHERE NOT NULL (CRM-BR-41) |
| score | smallint | no | CHECK 0–10 |
| category | varchar(10) | no | GENERATED: `promotor` (9–10), `pasivo` (7–8), `detractor` (0–6) |
| comment | text | sí | |
| channel_code | varchar(40) | no | catálogo `survey_channel` |
| responded_at | timestamptz | no | |
| recorded_by | uuid | no | FK `iam_users` |

### 3.10 `crm_cases` (reclamos y feedback de postventa)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| code | varchar(30) | no | UNIQUE (tenant_id, code) |
| client_id | uuid | no | FK `crm_clients` |
| reservation_id | uuid | sí | FK `booking_reservations` |
| survey_id | uuid | sí | FK `crm_surveys`; UNIQUE parcial |
| origin | varchar(20) | no | CHECK IN (`manual`,`nps_auto`) |
| type_code | varchar(40) | no | catálogo `case_type` |
| priority | varchar(10) | no | CHECK IN (`baja`,`media`,`alta`,`critica`) |
| subject | varchar(200) | no | |
| description | text | no | |
| status | varchar(20) | no | CHECK IN (`abierta`,`en_proceso`,`resuelta`,`cerrada`) |
| owner_id | uuid | no | FK `iam_users` |
| resolution | text | sí | `CHECK (status NOT IN ('resuelta') OR resolution IS NOT NULL)` |
| resolved_at | timestamptz | sí | |
| closed_at | timestamptz | sí | |
| lock_version | integer | no | default 0 |

Índices (tenant_id, status), (tenant_id, owner_id), (tenant_id, client_id), (tenant_id, created_at DESC).

### 3.11 `crm_case_events` (bitácora del caso)
| Columna | Tipo | Null | Notas |
|---|---|---|---|
| id | uuid | no | PK |
| tenant_id | uuid | no | |
| case_id | uuid | no | FK `crm_cases`, índice |
| event | varchar(20) | no | CHECK IN (`registro`,`estado`,`seguimiento`) |
| from_status / to_status | varchar(20) | sí | |
| message | text | no | |
| user_id | uuid | sí | NULL = Sistema |
| created_at | timestamptz | no | |

### 3.12 Tablas de otros dominios que el CRM lee o escribe
| Tabla | Uso desde CRM |
|---|---|
| `notif_messages` (Notificaciones) | "Enviar comunicación" desde el 360 y todas las notificaciones §8. Requiere columnas `client_id uuid null`, `related_type`, `related_id` para que la pestaña Comunicaciones filtre por FK (el prototipo filtra por texto, ver §11) |
| `audit_logs` (transversal) | Historial de lead/oportunidad/cliente/caso (§9) |
| `quote_quotes` | Lectura (pestañas, tabla de cotizaciones de la oportunidad); `opportunity_id` FK |
| `booking_reservations`, `booking_passengers` | Lectura 360 (reservas, pasajeros, destinos visitados, viajes por cerrar) |
| `fin_receivables`, `fin_payments`, `fin_invoices`, `ops_vouchers`, `fin_commissions` | Lectura 360 / panel de Ventas |

---

## 4. Máquinas de estado

### 4.1 Lead (`STATUS`: nuevo·Nuevo, contactado·Contactado, calificado·Calificado, convertido·Convertido, duplicado·Duplicado, descartado·Descartado)

| Desde | Hacia | Disparador (acción / endpoint) | Quién | Condiciones | Efectos |
|---|---|---|---|---|---|
| — | `nuevo` | Registrar lead (`POST /crm/leads`), ingreso de sistema | `crm.leads.manage` / Sistema | CRM-BR-01..06 | Score inicial (CRM-BR-07); si hay owner: audit `asignar` + notificación `lead.asignado` (si owner ≠ actor) |
| — | `duplicado` | Registrar con resolución `mark_duplicate` | idem | hay coincidencias | `duplicate_of` = primera coincidencia; nota "Coincide con X (correo y teléfono)"; no entra a la cola |
| — | (sin alta) | Resolución `merge` | idem | existe lead coincidente en `nuevo/contactado/calificado` | Se completan campos vacíos del existente, nota "[Fusionado dd mmm · fuente/canal] …", score +5 (máx 100); audit `fusionar` |
| `nuevo` | `contactado` | Completar actividad Llamada/Email/WhatsApp/Reunión relacionada al lead | dueño de la actividad | lead en `nuevo` | `first_contact_at`; audit `primer_contacto` |
| `nuevo`/`contactado`/`calificado` | `nuevo`/`contactado`/`calificado` | "Cambiar estado" (`POST …/status`) | `crm.leads.manage` + propietario | → `calificado` exige score ≥ 50; ≠ `nuevo` exige owner (CRM-BR-10/11) | → `contactado` fija `first_contact_at` si vacío; audit `cambio_estado` |
| `nuevo`/`contactado`/`calificado` | `calificado` | "Calificar (BANT)" con "Marcar como calificado" (`POST …/qualify`) | idem | score BANT ≥ 50 | Si no tenía owner: owner = actor (solo posible para supervisor); `first_contact_at` si vacío; audit `calificar` |
| `calificado` | `convertido` | "Convertir" (`POST …/convert`) | `crm.leads.manage` **y** `crm.opportunities.manage` + propietario | CRM-BR-12, 15–17 | Crea/vincula cliente, crea oportunidad, reunión opcional; audits `Cliente crear`, `Lead convertir`, `Oportunidad crear`; notificación `lead.convertido` |
| `nuevo`/`contactado`/`calificado` | `descartado` | "Descartar con motivo" (`POST …/discard`) | `crm.leads.manage` + propietario | motivo obligatorio | Guarda `discarded_at_step`; cierra actividades pendientes del lead (CRM-BR-14); audit `descartar` |
| `nuevo`/`contactado`/`calificado` | `duplicado` | "Marcar como duplicado" (`POST …/mark-duplicate`) | idem | existen coincidencias | `duplicate_of` = 1ª coincidencia; audit `marcar_duplicado` |
| (otro lead) `nuevo/contactado/calificado` | `duplicado` | "Fusionar en este lead" (`POST /crm/leads/{destino}/merge`) | idem | lead origen no convertido/duplicado | Origen → duplicado de destino; campos vacíos del destino se completan; `pax` si vacío; score = máx; actividades del origen pasan al destino (`merged_from_lead_id`); audits `fusionar` en ambos |
| `descartado`/`duplicado` | `nuevo` | "Reabrir lead" (`POST …/reopen`) | `crm.leads.manage` + propietario | — | Limpia `duplicate_of`; audit `reabrir` |
| `convertido` | — | Estado final (sin acciones salvo "Registrar actividad") | | | |

Asignación (no cambia estado): "Tomar lead" (cola → propia, CRM-BR-08), "Asignar/Reasignar responsable" (supervisor, CRM-BR-09).

Stepper de 8 pasos (UI): Lead recibido → Validar → Detectar duplicado → Registrar → Asignar → Primer contacto → Calificar → Convertir. Paso actual: convertido = 8 (todo completo); duplicado = fallo en paso 3; descartado = fallo en `discarded_at_step` (por defecto 6); sin owner = paso 5; nuevo = 6; contactado = 7; calificado = 8.

### 4.2 Oportunidad (`STATUS`: calificacion, descubrimiento, cotizacion, negociacion, ganada, perdida)

| Desde | Hacia | Disparador | Quién | Condiciones | Efectos |
|---|---|---|---|---|---|
| — | `calificacion`/`descubrimiento`/`cotizacion`/`negociacion` | Alta manual (etapa inicial elegible), conversión de lead (solo `calificacion`/`descubrimiento`, default `descubrimiento`), recompra (`calificacion`) | `crm.opportunities.manage` | CRM-BR-20/21 | probability = `default_probability` de la etapa; audit `crear`; notificación `oportunidad.asignada` si owner ≠ actor |
| abierta | otra abierta | Drag & drop, menú ⋯, "Mover a" (`POST …/stage`) | `crm.opportunities.manage` + propietario | — | probability = default de la etapa destino; audit `cambio_etapa` |
| abierta | `ganada` | "Marcar como ganada" | idem | Confirmación; **advertencia no bloqueante** si no hay cotización `aprobada`/`convertida` vinculada (CRM-BR-23) | probability 100, `closed_at` = hoy, limpia motivo; audit; notificación `oportunidad.ganada` al owner |
| abierta | `perdida` | "Marcar como perdida" | idem | motivo obligatorio; "Otro" exige detalle (CRM-BR-24) | probability 0, `closed_at` = hoy, `lost_at_stage` = etapa previa; audit con motivo; notificación `oportunidad.perdida` |
| `ganada`/`perdida` | abierta | "Reabrir oportunidad" / mover desde columna cerrada | idem | Confirmación "Reabrir…" | Destino por defecto: `lost_at_stage` si es abierta, si no `negociacion`; probability = default; `closed_at` NULL; desde `perdida` limpia motivo; audit `cambio_etapa` |
| `ganada` | `perdida` (y viceversa) | Mover entre columnas cerradas | idem | perdida exige motivo | idem anteriores |
| `calificacion`/`descubrimiento` | `cotizacion` | **Automático**: se crea una cotización con `opportunity_id` (evento `QuoteCreated`, módulo Ventas) | Sistema | — | probability 55; audit `cambio_etapa` |
| no `ganada`/`perdida`/`negociacion` | `negociacion` | **Automático**: se registra negociación en la cotización (`QuoteNegotiationRecorded`) | Sistema | — | probability 70 |
| ≠ `ganada` | `ganada` | **Automático**: cotización convertida en reserva (`QuoteConverted`) | Sistema | — | probability 100, `closed_at` = hoy (el prototipo sobrescribe `closeDate`, ver §11) |

Stepper UI: Calificación → Descubrimiento → Cotización → Negociación → Ganada; si perdida: primeros 4 + "Perdida" con fallo en `lost_at_stage`.

### 4.3 Actividad (`STATUS`: pendiente, vencida, completada)

| Desde | Hacia | Disparador | Quién | Efectos |
|---|---|---|---|---|
| — | `pendiente` | Crear (manual, "Tomar lead", asignar lead con primer contacto, conversión con reunión, completar con próxima, "NPS ≤ 6", llamada de postventa) | `crm.activities.manage` / Sistema | audit `crear`; notificación `actividad.asignada` (Push) si owner ≠ actor |
| `pendiente` | `vencida` | Job JOB-04 (cada hora) cuando `due_at < now()` | Sistema | Recordatorio al owner. La UI además deriva "vencida" en tiempo real (`due < ahora`) |
| `pendiente`/`vencida` | `completada` | "Completar" con resultado | owner (Ventas) o supervisor | `completed_at`; opcional crea la próxima (`previous_activity_id`); lead `nuevo`→`contactado` (CRM-BR-30); audit `completar` |
| `pendiente`/`vencida` | `pendiente` | "Reprogramar" | idem | nuevo `due_at`; audit `reprogramar` (antes → después · motivo) |
| `pendiente`/`vencida` | `completada` (auto) | Lead descartado | Sistema | result "Cerrada automáticamente: lead descartado", `auto_closed` |
| `completada` | — | Final | | |

### 4.4 Caso de postventa (`STATUS`: abierta, en_proceso, resuelta, cerrada)

| Desde | Hacia | Disparador | Condición | Efectos |
|---|---|---|---|---|
| — | `abierta` | Registro manual (tipo ≠ Felicitación) o automático por NPS ≤ 6 | — | evento `registro`; audit `crear`; notificación `postventa.caso` al owner si ≠ actor (manual) / `postventa.detractor` (auto) |
| — | `cerrada` | Registro manual de tipo **Felicitación** | — | Nace cerrado |
| `abierta` | `en_proceso` | "Iniciar atención" | — | evento `estado` |
| `abierta`/`en_proceso` | (igual) | "Registrar seguimiento" | nota obligatoria | evento `seguimiento`; audit `seguimiento` |
| `abierta`/`en_proceso` | `resuelta` | "Resolver" | resolución obligatoria | `resolution`, `resolved_at`; evento `estado` |
| `resuelta` | `cerrada` | "Cerrar caso" | — | `closed_at`; notificación Email al cliente `postventa.cierre` |

Stepper UI: Abierta → En proceso → Resuelta → Cerrada.

### 4.5 Oportunidad de cotización/ reserva / CxC
No son de este módulo; el CRM solo los muestra con `badge(status)` (etiquetas de `STATUS` en core.js).

---

## 5. Reglas de negocio

| ID | Regla | Dónde se valida |
|---|---|---|
| PROC-004 / CRM-BR-01 | Lead: obligatorios nombre, canal, fuente, interés | FormRequest `StoreLeadRequest` |
| CRM-BR-02 | Al menos correo **o** teléfono. Correo con formato `^[^\s@]+@[^\s@]+\.[^\s@]{2,}$`; teléfono ≥ 7 dígitos. Mensaje: "Validación PROC-004: registre al menos correo o teléfono." | FormRequest + CHECK DB |
| CRM-BR-03 | Detección de duplicados en alta y edición de lead: coincidencia por `email_norm` (minúsculas, trim) o `phone_norm` (últimos 9 dígitos) contra **leads** (excepto el propio) **y clientes**. Resultado: lista con tipo, id, nombre, estado/segmento y campo(s) coincidente(s) | Service `LeadDuplicateFinder` |
| CRM-BR-04 | Si hay duplicados al registrar, el alta exige decisión explícita: `merge` (solo si hay un lead coincidente en nuevo/contactado/calificado), `mark_duplicate` (de la 1ª coincidencia) o `create_new` (queda nota "Registrado pese a coincidencia con X"). Sin decisión → `409 CRM-BR-04` con las coincidencias | Service |
| CRM-BR-05 | Fecha tentativa de viaje no puede ser anterior a hoy (zona del tenant) | FormRequest |
| CRM-BR-06 / PRD-BR-002 | Producto de interés: solo productos `ACTIVE` o `APPROVED` | FormRequest (exists + status) |
| CRM-BR-07 | Score inicial = min(70, 25 + 8·correo + 8·teléfono + 10·fecha + 5·(pax>0) + 8·producto + 4·empresa + 6·(fuente Referido o Llamada entrante)) | Service |
| CRM-BR-08 | "Tomar lead": solo leads sin owner y no convertidos/descartados. Operación atómica (`UPDATE … WHERE owner_id IS NULL`); si otro asesor lo tomó antes → `409` "Otro asesor tomó el lead antes que usted". Crea actividad Llamada "Primer contacto — {nombre}" hoy a la hora (ahora+2 h, tope 20:00); notifica a todos los Manager activos | Service + lock optimista |
| CRM-BR-09 | Asignar/reasignar leads, oportunidades, actividades y clientes entre asesores: solo supervisor (Tenant Admin, Manager) con `crm.leads.manage`. Mensaje a Ventas: "Solo Gerencia o el Administrador pueden reasignar…". Reasignar exige asesor distinto al actual; motivo opcional. Al asignar un lead se puede programar primer contacto (+2 h) | Policy `reassign` (permiso nuevo `crm.records.reassign`, ver §11) |
| CRM-BR-10 | Pasar a `calificado` exige score ≥ 50 ("PROC-005: para marcar como calificado el score debe ser ≥ 50") | Service (state machine) |
| CRM-BR-11 | Avanzar un lead más allá de `nuevo` exige responsable ("PROC-004: asigne un responsable antes de avanzar el lead") | Service + CHECK DB |
| CRM-BR-12 / PROC-005 | Solo leads `calificado` se convierten; requiere `crm.leads.manage` y `crm.opportunities.manage` | Policy + Service |
| CRM-BR-13 | "Convertido" y "Descartado" no se eligen en "Cambiar estado"; solo con sus acciones específicas (trazabilidad) | FormRequest (`status in nuevo,contactado,calificado`) |
| CRM-BR-14 | Descartar exige motivo del catálogo (+ detalle opcional); el lead no se elimina (RT-24); las actividades pendientes del lead se cierran automáticamente | Service |
| CRM-BR-15 / RT-10 | Documento de cliente: DNI = 8 dígitos; RUC = 11 dígitos iniciando en 10, 15, 17 o 20; empresa con país Perú requiere RUC; número obligatorio | FormRequest `ClientDocumentRule` + CHECK |
| CRM-BR-16 | No puede existir otro cliente con el mismo tipo+número de documento (bloqueante: "Ya existe un cliente con … Vincúlelo") | Service + UNIQUE DB |
| CRM-BR-17 | Cliente: correo o teléfono obligatorio; si correo/teléfono coincide con otro cliente → advertencia con confirmación ("Guardar igualmente"), no bloqueante (`confirm_duplicate=true`) | FormRequest + Service |
| CRM-BR-18 | Conversión: si el contacto ya existe como cliente se propone "Vincular a cliente existente". Al vincular, si el correo del lead no está en el cliente ni en sus contactos se agrega como contacto no principal. Cliente nuevo: owner = owner del lead (o actor), tags = [fuente], `source_lead_id`; empresa → contacto principal = persona del lead. Segmento sugerido: Corporativo si interés "corporativo"; Grupos si "promoción" o pax ≥ 10; si no Retail | Service `ConvertLeadAction` (transacción única) |
| CRM-BR-19 / PROC-005 | La conversión conserva el lead (estado `convertido` + `converted_client_id`, `converted_opportunity_id`, `converted_at`, `converted_by`); su historial y actividades se muestran en el 360 y en la oportunidad | Service |
| CRM-BR-20 | Oportunidad: cliente, responsable, título, monto, moneda (PEN/USD), fecha de cierre obligatorios; monto > 0; en alta, fecha de cierre ≥ hoy; probabilidad editable 0–100 solo en edición | FormRequest |
| CRM-BR-21 | Ventas solo puede crear oportunidades para clientes de su cartera y siempre como owner | Policy + FormRequest |
| CRM-BR-22 | Cambio de etapa fija probabilidad = default de la etapa (ganada 100, perdida 0) y `closed_at` para cerradas | Service |
| CRM-BR-23 / RT-3 | Ganar sin cotización aprobada/convertida está permitido con advertencia ("la reserva se genera desde la cotización aprobada") | Service (warning en respuesta `meta.warnings`) |
| CRM-BR-24 / PROC-006 | Perder exige motivo; "Otro" exige detalle | FormRequest |
| CRM-BR-25 | No se puede crear cotización desde una oportunidad cerrada ("PROC-006: no se puede cotizar una oportunidad cerrada"); requiere `quotes.create` | Policy (módulo Cotizaciones) |
| CRM-BR-26 | Reabrir una oportunidad cerrada requiere confirmación y queda auditado | Service |
| CRM-BR-27 | Sincronización desde Cotizaciones: alta → `cotizacion` (si estaba en calificación/descubrimiento); negociación → `negociacion`; conversión en reserva → `ganada` | Listener de eventos de Cotizaciones |
| CRM-BR-28 / PROC-007 | Actividad: tipo, asunto, responsable, fecha y hora obligatorios; si hay tipo de relación, el registro relacionado es obligatorio y debe ser visible para el actor | FormRequest |
| CRM-BR-29 | Completar exige resultado; la próxima actividad (activada por defecto) exige asunto, fecha y hora; hereda relación y responsable | FormRequest + Service |
| CRM-BR-30 | Completar una actividad de tipo Llamada/Email/WhatsApp/Reunión vinculada a un lead en `nuevo` lo pasa a `contactado` | Listener `ActivityCompleted` |
| CRM-BR-31 | Ventas: solo puede ser responsable de sus actividades; completa/reprograma solo las propias. Supervisor asigna a asesores (Tenant Admin, Manager, Ventas activos) o a usuarios de Operaciones activos | Policy + FormRequest |
| CRM-BR-32 | Reprogramar: fecha y hora obligatorias, motivo opcional; la actividad vuelve a `pendiente`. (El prototipo no impide una fecha pasada; propuesta: `due_at ≥ now()`, ver §12) | FormRequest |
| CRM-BR-33 / PROC-008 | Ventas ve en el 360 solo los clientes de su cartera: `owner` = yo **o** con oportunidad/cotización/reserva mía. Dentro del 360 solo ve sus oportunidades, cotizaciones, reservas, actividades y las CxC/pagos/comprobantes/vouchers de sus reservas; leads: propios o sin asignar | Policy + scopes Eloquent |
| CRM-BR-34 | KPIs del cliente: total comprado = Σ reservas no `cancelada`/`borrador` en PEN; ticket promedio = total / nº; saldo = Σ(monto − pagado) de CxC no `pagada`/`cancelada`; vencida si estado `vencida` o vencimiento < hoy (alerta "Coordine con Finanzas antes de nuevas reservas a crédito") | Read model / Query |
| CRM-BR-35 | Enviar comunicación desde el 360: canal Email/WhatsApp/Portal, asunto obligatorio; requiere destino (correo para Email, teléfono para WhatsApp) | FormRequest + Service |
| CRM-BR-36 | Contactos: nombre obligatorio; el primero es principal; "Hacer principal" desmarca los demás | Service + UNIQUE parcial |
| CRM-BR-37 / PROC-009 | Viajes por cerrar: reservas `completada` con fin de viaje en los últimos 60 días, o `en_operacion` que terminan en ≤ 5 días | Query |
| CRM-BR-38 | Encuesta: cliente y puntaje 0–10 obligatorios; reserva opcional (no cancelada, visible para el actor); encuestar un viaje aún en operación muestra aviso, no bloquea | FormRequest |
| CRM-BR-39 | NPS ≤ 6 (detractor) genera automáticamente: caso `Reclamo` (prioridad `alta` si ≤ 3, si no `media`; owner = responsable del cliente; asunto "Detractor NPS n — reserva"), actividad Postventa "Resolver REC-… — contactar a …" mañana 10:00 y notificación Email al responsable | Listener `DetractorDetected` (misma transacción que la encuesta) |
| CRM-BR-40 | La última encuesta actualiza `crm_clients.nps_last` (audit `actualizar_nps`) | Service |
| CRM-BR-41 | Una sola encuesta por reserva ("La reserva … ya tiene una encuesta registrada") | UNIQUE parcial + Service |
| CRM-BR-42 | NPS = round((% promotores − % detractores)·100); promotor 9–10, pasivo 7–8, detractor 0–6 | Read model |
| CRM-BR-43 | Caso: cliente, asunto, descripción y responsable obligatorios; Felicitación nace `cerrada`; resolver exige texto; notificación al cliente al cerrar | FormRequest + Service |
| CRM-BR-44 | Gestionar un caso: `crm.clients.manage` y, para Ventas, ser owner del caso o tener al cliente en cartera | Policy |
| CRM-BR-45 / PROC-010 | Recompra: requiere `crm.opportunities.manage` y cliente visible. Si el cliente ya tiene oportunidades abiertas se pide confirmación. Precarga: título "Recompra — {cliente} — {sugerencia}", destino sugerido por mapa (Cusco→Arequipa y Cañón del Colca; Arequipa→Cusco y Machu Picchu; Ica→Cusco y Valle Sagrado; Machu Picchu→Lago Titicaca — Puno; Puno→Paracas e Islas Ballestas; por defecto Cusco y Machu Picchu), monto = ticket promedio (o 4 000), PEN, `calificacion`, cierre +30 d, origen "Fidelización"; audit `Cliente accion_fidelizacion` | Service + FormRequest |
| CRM-BR-46 | Segmentación: filtros segmento, última compra (≤ 30 d, > 90 d, > 180 d, sin compras) y destino visitado (departamento de los productos reservados + destinos de oportunidades ganadas) | Query |
| CRM-BR-47 | Alcance por rol obligatorio en backend en listas, KPIs, búsquedas, selects de relación y exportaciones (ver §7) | Global scope + Policy |
| CRM-BR-48 | KPIs consolidados en PEN con el tipo de cambio vigente del tenant (`settings.fx`); los montos de cada registro se conservan en su moneda (RT-15) | Read model |
| RT-16 / PROC-063 | Toda acción de §9 se audita | Observer/Service |
| RT-17 | Cada botón oculto/deshabilitado en UI se revalida en API (403) | Policy |
| RT-24 | Sin borrado físico; leads descartados/duplicados permanecen en historial | Sin endpoint DELETE |

---

## 6. API REST

Prefijo `/api/v1`. `own` = aplica alcance de Ventas (CRM-BR-33/47). Respuestas de negocio: `409` con `code` de regla; `403` con `code: SCOPE_OWNERSHIP` cuando el registro es de otro asesor.

### 6.1 Leads
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/crm/leads` | `crm.leads.view` | Lista. `filter[status]`, `filter[source]`, `filter[owner]=me\|none\|any\|{userId}`, `filter[q]` (id, nombre, empresa, correo, teléfono, destino, notas), `sort=-created_at`, cursor. Ventas: propios + sin asignar. `meta.kpis`: nuevos hoy, sin contactar (y sin asignar), calificados, conversión (convertidos / no duplicados), por fuente, por estado | — |
| GET | `/crm/leads/duplicates?email=&phone=&exclude=` | `crm.leads.manage` | Chequeo en vivo (debounce 250 ms en UI) | — |
| POST | `/crm/leads` | `crm.leads.manage` | Alta. Body opcional `duplicate_resolution: merge\|mark_duplicate\|create_new`, `merge_into`. Ventas: `owner_id` forzado a sí mismo | `Idempotency-Key` recomendado |
| GET | `/crm/leads/{code}` | `crm.leads.view` + own (o sin asignar) | Ficha + BANT vigente + duplicados + actividades + historial (audit + actividades completadas + "recibido") | — |
| PATCH | `/crm/leads/{code}` | `crm.leads.manage` + propietario | Editar (no convertido/descartado). Ventas no cambia owner | `lock_version` |
| POST | `/crm/leads/{code}/take` | `crm.leads.manage` | Tomar lead de la cola (CRM-BR-08) | atómico; `409` si ya tomado |
| POST | `/crm/leads/{code}/assign` | `crm.records.reassign` | `{owner_id, schedule_first_contact:bool}` | `lock_version` |
| POST | `/crm/leads/{code}/status` | `crm.leads.manage` + propietario | `{status: nuevo\|contactado\|calificado, note}` | `lock_version` |
| POST | `/crm/leads/{code}/qualify` | idem | `{budget, authority, need, timeline, declared_amount, currency, notes, mark_qualified:bool}` → inserta en `crm_lead_qualifications`; respuesta indica si alcanzó umbral | `lock_version` |
| POST | `/crm/leads/{code}/discard` | idem | `{reason_code, detail}` | `lock_version` |
| POST | `/crm/leads/{code}/reopen` | idem | desde descartado/duplicado | `lock_version` |
| POST | `/crm/leads/{code}/mark-duplicate` | idem | `{duplicate_of_type, duplicate_of_id}` (debe estar entre las coincidencias) | `lock_version` |
| POST | `/crm/leads/{code}/merge` | idem | `{source_lead_id}` fusiona el otro lead dentro de este | transacción |
| POST | `/crm/leads/{code}/convert` | `crm.leads.manage` + `crm.opportunities.manage` + propietario | `{client_mode: new\|existing, client:{kind,name,doc_type,doc_number,segment,city,country}\|client_id, opportunity:{title,stage,amount,currency,expected_close_date,owner_id}, schedule_discovery_meeting:bool}` → `{client, opportunity, activity?}` | **`Idempotency-Key` obligatorio**; transacción única; cola `critical` para eventos |

### 6.2 Oportunidades
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/crm/pipeline-stages` | `crm.opportunities.manage` | Etapas con probabilidad por defecto | cache Redis por tenant |
| GET | `/crm/opportunities` | idem + own | `filter[stage]`, `filter[open]=true\|false`, `filter[owner]`, `filter[q]` (título, id, cliente, destino), `group_by=stage` (kanban: items ordenados por cierre + total PEN por columna). `meta.kpis`: pipeline abierto, ponderado, ganadas del mes (por `closed_at`), tasa de cierre; `meta.totals` (monto y ponderado en PEN) | — |
| POST | `/crm/opportunities` | idem | Alta (manual, recompra `origin=Fidelización`) | `Idempotency-Key` recomendado |
| GET | `/crm/opportunities/{code}` | idem + own | Ficha + cotizaciones vinculadas (con total de versión vigente) + actividades (propias, del lead y de sus cotizaciones) + historial | — |
| PATCH | `/crm/opportunities/{code}` | idem + own | Editar datos y probabilidad (no la etapa) | `lock_version` |
| POST | `/crm/opportunities/{code}/stage` | idem + own | `{stage, lost_reason_code?, lost_detail?, confirm_reopen?}`. Respuesta con `meta.warnings` (CRM-BR-23) | `lock_version` |
| POST | `/crm/opportunities/{code}/reassign` | `crm.records.reassign` | `{owner_id, note}` | `lock_version` |

### 6.3 Actividades
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/crm/activities` | `crm.activities.manage` + own | `filter[type]`, `filter[owner]`, `filter[status]=vencida\|hoy\|proximas\|completada`, `filter[from]/[to]` (semana), `filter[related_type]/[related_id]`. `meta.kpis`: vencidas, hoy, próximos 7 días, completadas 7 días | — |
| GET | `/crm/activities/related-options?type=lead\|opportunity\|client\|quote` | idem | Opciones visibles para el actor (leads no descartados propios; oportunidades/cotizaciones propias; clientes en cartera) | — |
| POST | `/crm/activities` | idem | Alta | — |
| GET | `/crm/activities/{code}` | idem + own | Drawer: datos + auditoría | — |
| POST | `/crm/activities/{code}/complete` | idem + own | `{result, next?:{type,subject,due_at}}` → aplica CRM-BR-30 | `Idempotency-Key` (evita doble próxima actividad) |
| POST | `/crm/activities/{code}/reschedule` | idem + own | `{due_at, reason}` | — |
| POST | `/crm/activities/{code}/reassign` | `crm.records.reassign` | `{owner_id, note}` (solo no completadas) | — |

### 6.4 Clientes y Customer 360
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/crm/clients` | `crm.clients.manage` \| `finance.receivables` (+ own) | `filter[segment]`, `filter[kind]`, `filter[owner]`, `filter[q]` (id, nombre, documento, correo, teléfono, ciudad, tags). Filas con agregados `reservations_count`, `total_pen`, `balance_pen`, `nps`, `last_purchase_date`. `meta.kpis` | — |
| GET | `/crm/clients/duplicates?doc_type=&doc_number=&email=&phone=&exclude=` | `crm.clients.manage` | Duplicado duro (documento) y blando (correo/teléfono) | — |
| POST | `/crm/clients` | `crm.clients.manage` | Alta; `confirm_duplicate` para CRM-BR-17 | — |
| GET | `/crm/clients/{code}` | lectura + own | Cabecera + KPIs (CRM-BR-34) + alertas (saldo vencido, casos abiertos, `partial_view` para Ventas) + destinos visitados | — |
| PATCH | `/crm/clients/{code}` | `crm.clients.manage` + own | Editar (Ventas no cambia owner) | — |
| POST | `/crm/clients/{code}/reassign` | `crm.records.reassign` | | — |
| GET | `/crm/clients/{code}/{tab}` | lectura + own | `tab` ∈ `leads`, `opportunities`, `quotes`, `reservations`, `passengers` (consolidado por documento/nombre con sus reservas), `receivables`, `payments`, `invoices`, `vouchers`, `communications`, `activities`, `timeline` (alta, leads, oportunidades creadas/cerradas, cotizaciones creadas/aprobadas, reservas, pagos, encuestas, casos; desc por fecha), `audit` (cliente + leads + oportunidades + cotizaciones + reservas + pagos + casos) | — |
| POST | `/crm/clients/{code}/contacts` | `crm.clients.manage` + own | Agregar contacto | — |
| POST | `/crm/clients/{code}/contacts/{id}/make-primary` | idem | | — |
| POST | `/crm/clients/{code}/communications` | idem | `{channel: email\|whatsapp\|portal, subject, body}` → `notif_messages` (cola `notifications`) | `Idempotency-Key` |
| GET | `/crm/clients/{code}/invoices/{id}/preview`, `/vouchers/{id}/preview` | lectura + own | URLs firmadas del PDF (Finanzas/Operaciones) | — |

### 6.5 Postventa y fidelización
| Método | Endpoint | Permiso | Descripción | Idempotente/lock |
|---|---|---|---|---|
| GET | `/crm/after-sales/trips` | `crm.clients.manage` + own | CRM-BR-37 con encuesta asociada (si existe) | — |
| GET | `/crm/surveys` | idem | Lista + `meta.stats` (NPS, promedio, distribución 0–10, promotores/pasivos/detractores) | — |
| POST | `/crm/surveys` | idem | `{client_id, reservation_id?, channel_code, score, comment}` → encuesta (+ caso + actividad si ≤ 6) | **`Idempotency-Key`** |
| GET | `/crm/cases` | idem + own | `filter[status]`, `filter[type]`, `filter[q]` | — |
| POST | `/crm/cases` | idem | Registro manual | `Idempotency-Key` |
| GET | `/crm/cases/{code}` | idem + own | Drawer con bitácora | — |
| POST | `/crm/cases/{code}/start` | idem + CRM-BR-44 | abierta → en_proceso | `lock_version` |
| POST | `/crm/cases/{code}/follow-ups` | idem | `{message}` | — |
| POST | `/crm/cases/{code}/resolve` | idem | `{resolution}` | `lock_version` |
| POST | `/crm/cases/{code}/close` | idem | resuelta → cerrada | `lock_version` |
| GET | `/crm/loyalty/segments` | idem + own | CRM-BR-46; filas con última compra, días, destinos, total PEN, NPS, `has_open_opportunity` | — |
| GET | `/crm/clients/{code}/rebuy-proposal` | `crm.opportunities.manage` + own | Precarga CRM-BR-45 + oportunidades abiertas existentes | — |

La creación de la oportunidad de recompra usa `POST /crm/opportunities` con `origin=Fidelización` y `client_id`; el backend registra además `Cliente accion_fidelizacion`.

### 6.6 Panel de Ventas y exportación
| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/dashboard/sales` | rol con `crm.leads.view` y alcance own | Read model §7.4 (cache Redis 60 s por usuario, invalidado por eventos CRM/Cotizaciones) |
| POST | `/exports` | según recurso (`reports.export` no es requerido hoy en CRM, ver §12) | `{resource: crm.leads\|crm.opportunities\|crm.clients\|crm.cases\|crm.loyalty_segments, filters}` → job cola `reports`, respeta alcance; audit `Exportación exportar` |

Las acciones "Hecha" y "Tomar" del panel usan **los mismos endpoints** `POST /crm/activities/{code}/complete` y `POST /crm/leads/{code}/take` (ver §11, unificación).

---

## 7. Permisos y comportamiento por rol

### 7.1 Permisos del módulo (actuales en `DB.rolePerms` / `permissionGroups`)
| Permiso | Habilita |
|---|---|
| `crm.leads.view` | Ruta Leads; entrada al módulo CRM (`crmAllowed` = `crm.leads.view` o `crm.clients.manage`) |
| `crm.leads.manage` | Nuevo lead, editar, tomar, calificar, cambiar estado, descartar, marcar duplicado/fusionar, reabrir; (con supervisor) asignar/reasignar |
| `crm.opportunities.manage` | Ruta Oportunidades; crear/editar/mover/ganar/perder/reabrir; convertir lead (junto a leads.manage); recompra; "Nueva oportunidad" en el 360 |
| `crm.activities.manage` | Ruta Actividades; crear/completar/reprogramar; botones "Actividad" en fichas |
| `crm.clients.manage` | Crear/editar clientes, contactos, comunicaciones; ruta Postventa; encuestas, casos, botón de recompra |
| `finance.receivables` | Acceso de solo lectura a Clientes 360 |
| `quotes.create` | "Crear cotización" desde la oportunidad; "Nueva cotización" en el panel |
| `crm.targets.view/manage`, `crm.claims.manage` | No usados por `crm.js` (Metas y Libro de reclamaciones) |

### 7.2 Matriz rol × pantalla / acción
| Rol | Leads | Oportunidades | Actividades | Clientes 360 | Postventa | Alcance |
|---|---|---|---|---|---|---|
| Tenant Admin (U01) | Todo + asignar/reasignar + filtro Asesor | Todo + reasignar | Todo + reasignar; puede asignar a Operaciones | Todo + reasignar | Todo | Todo el tenant; filtro "Asesor" |
| Manager (U02) | idem | idem | idem | idem | idem | idem |
| Ventas (U03/U04) | Ver propios + cola sin asignar; gestionar solo propios; "Tomar lead" | Solo propias; crear siempre como owner | Solo propias; responsable = yo | Cartera (CRM-BR-33); vista parcial con aviso | Viajes/encuestas/casos de su cartera | "Mi cartera" |
| Operaciones (U05/U06) | ✗ (403) | ✗ | ✗ (aunque puede ser responsable de actividades, ver §12) | ✗ | ✗ | — |
| Finanzas (U07) | ✗ | ✗ | ✗ | **Solo lectura** (alerta "Modo consulta"), sin botones | ✗ | Todo el tenant |
| Contabilidad (U08) | ✗ | ✗ | ✗ | **Solo lectura** | ✗ | Todo el tenant |
| Super Admin (U16) | ✗ (consola de plataforma; aislamiento) | ✗ | ✗ | ✗ | ✗ | — |
| Externos (guía, conductor, B2B, cliente, pasajero, proveedor) | ✗ (portal propio; 403 "pertenece al back-office") | ✗ | ✗ | ✗ | ✗ | — |

Todo acceso denegado por ruta muestra pantalla 403 y se audita (`Acceso acceso_denegado`); registro ajeno para Ventas → pantalla "Registro fuera de su cartera" (API `403 SCOPE_OWNERSHIP`).

### 7.3 Detalle de UI por permiso (qué se oculta o deshabilita)
| Elemento | Regla de visibilidad |
|---|---|
| "Nuevo lead", "Nueva oportunidad", "Nueva actividad", "Nuevo cliente" | Ocultos sin el permiso `*.manage` correspondiente; en su lugar alerta "Modo consulta: su rol no puede … (permiso x)" |
| Acciones de la ficha de lead | Visibles según estado; **deshabilitadas con tooltip** si falta `crm.leads.manage` ("Requiere permiso crm.leads.manage") o el lead no es propio ("Tome el lead para gestionarlo"). "Tomar lead" solo si sin owner, no cerrado y con permiso. "Asignar/Reasignar responsable" solo supervisor. "Registrar actividad" deshabilitado sin `crm.activities.manage` o sin propiedad. Botones de duplicados solo si gestionable y no cerrado |
| Kanban | Tarjetas `draggable` y botón ⋯ solo con `crm.opportunities.manage` |
| Ficha oportunidad | "Reasignar" (supervisor), "Editar" y "Mover a"/Ganar/Perder/Reabrir (`crm.opportunities.manage`), "Crear cotización" (abierta + `quotes.create`), "+ Actividad" (`crm.activities.manage`) |
| Actividad (lista/drawer) | "Completar"/"Reprogramar" solo si no completada + permiso + propia; "Reasignar" supervisor y no completada |
| Customer 360 | "Reasignar" supervisor; "Editar", "+ Contacto", "Hacer principal", "Enviar comunicación" con `crm.clients.manage`; "Actividad" con `crm.activities.manage`; "Nueva oportunidad" con `crm.opportunities.manage`. Finanzas/Contabilidad: ninguno |
| Postventa | "Registrar reclamo/feedback", "Registrar encuesta NPS", botones NPS/llamada por viaje y "Crear oportunidad de recompra" con `crm.clients.manage`; acciones del caso solo si CRM-BR-44 (si no, alerta "Su rol no puede gestionar casos de postventa") |
| Selector "Responsable" | Ventas: fijo en sí mismo con hint "La reasignación la realiza Gerencia"; supervisor: asesores activos (Tenant Admin, Manager, Ventas) y, en actividades, además Operaciones |
| Control de alcance en cabecera | Ventas: chip "Mi cartera"; supervisor: select "Asesor: todos/…" (filtra leads, oportunidades, actividades, clientes, casos, viajes por `owner`; alerta "Mostrando solo la cartera de …"); otros roles: nada |
| Márgenes | El CRM no muestra costos ni márgenes, salvo el panel de Ventas (margen de **sus** cotizaciones) |

### 7.4 Panel de Ventas (`dashboard`, rol `sales`)
| Bloque | Contenido / cálculo |
|---|---|
| Cabecera | Saludo + "Panel de Ventas · Mi cartera". Botones "Nuevo lead" (`crm.leads.manage`) y "Nueva cotización" (`quotes.create`), deshabilitados con tooltip si falta el permiso |
| Alerta | Si hay actividades hoy o vencidas: "n actividad(es) para hoy o vencidas. Los recordatorios se envían cada hora (JOB-04)" |
| KPI Mi pipeline | Σ oportunidades abiertas propias en PEN; sub: nº y ponderado |
| KPI Mis cotizaciones abiertas | Propias en `borrador/enviada/vista/negociacion`; sub: nº que vencen en ≤ 7 días (excluye borrador) |
| KPI Actividades hoy/vencidas | Propias no completadas con estado vencida o fecha ≤ hoy; sub: total pendientes |
| KPI Mi conversión | ganadas / (ganadas + perdidas); sub: leads convertidos / total de mis leads |
| KPI Comisión estimada (mes) | Σ `fin_commissions` con beneficiario = yo, estado ≠ anulada y reserva con inicio de viaje en el mes actual, en PEN; sub: desglose por moneda |
| Mi pipeline por etapa | Barras por etapa abierta (nº y monto PEN) + 4 oportunidades con cierre más próximo |
| Mis actividades | "Hoy y vencidas" con botón "Hecha" (modal resultado obligatorio) + 3 próximas |
| Mis cotizaciones por vencer | Título, cliente, vence en n d / hoy / vencida, vista/no abierta, total y **margen** (rojo < 15 %) — solo propias |
| Mis leads | Top 5 propios en nuevo/contactado/calificado (nombre, destino, pax, score, estado) + "Sin asignar · disponibles para tomar" (sin owner en nuevo/contactado) con botón "Tomar" |
| Mis próximos viajes | 4 reservas propias no canceladas/completadas/borrador por fecha de inicio + accesos rápidos (Lead, Cotización, Mis clientes, Postventa) |

Supervisor: el panel ejecutivo/gerencia (otra especificación) consume los mismos datos CRM sin alcance (pendientes del equipo, leads sin contactar/sin asignar, desempeño por asesor).

---

## 8. Eventos, jobs (colas Redis) y notificaciones

### 8.1 Eventos de dominio
| Evento | Emisor | Listeners (cola) |
|---|---|---|
| `LeadCreated` | alta / ingreso sistema | notificación si asignado (`notifications`) |
| `LeadAssigned` / `LeadTaken` | assign / take | notificación al asesor / a Managers (`notifications`) |
| `LeadQualified`, `LeadStatusChanged`, `LeadDiscarded`, `LeadMerged` | servicios | auditoría (sync), read models (`low`) |
| `LeadConverted` | convert | notificación owner (`notifications`), `ClientCreated`, `OpportunityCreated` (`critical` para consistencia) |
| `OpportunityCreated`, `OpportunityStageChanged`, `OpportunityWon`, `OpportunityLost`, `OpportunityReassigned` | servicios | notificaciones, invalidar cache de dashboards (`default`) |
| `ActivityScheduled`, `ActivityCompleted`, `ActivityRescheduled`, `ActivityReassigned` | servicios | `ActivityCompleted` → CRM-BR-30; notificación de asignación |
| `SurveyRecorded` / `DetractorDetected` | encuesta | caso + actividad (misma transacción) + Email al responsable |
| `CaseCreated`, `CaseStatusChanged`, `CaseClosed` | casos | notificación al owner / al cliente |
| `ClientCommunicationRequested` | 360 | adapter del canal vía `notif_messages` (`notifications`, reintentos/backoff) |
| Consumidos: `QuoteCreated`, `QuoteNegotiationRecorded`, `QuoteConverted` (Cotizaciones) | módulo Ventas | CRM-BR-27 (`default`) |
| Consumido (propuesto): `ReservationCompleted` (Operaciones) | cierre operativo | crear actividad Postventa (ver §12) |

### 8.2 Jobs programados
| Job | Frecuencia | Qué hace en CRM |
|---|---|---|
| JOB-04 Recordatorio de actividades CRM | cada hora | Marca `vencida` las pendientes con `due_at < now()` y envía recordatorio al owner de las vencidas o del día (Push/Portal) |
| (existente en Admin) JOB-08 Reportes programados | lunes 07:00 | Puede incluir KPIs CRM de gerencia |
| Exportaciones CSV | on-demand | Cola `reports`; archivo en S3; auditoría |

### 8.3 Notificaciones que emite el prototipo
| Evento (`event`) | Canal | Destinatario | Asunto |
|---|---|---|---|
| `lead.asignado` | Portal | asesor asignado | "Lead asignado: {nombre} ({code})" / "Nuevo lead asignado…" |
| `lead.tomado` | Portal | todos los Manager activos | "{asesor} tomó el lead {code} ({nombre})" |
| `lead.convertido` | Portal | owner de la oportunidad | "Lead {code} convertido en {OPP}" |
| `oportunidad.asignada` | Portal | owner (si ≠ actor) | "Oportunidad asignada: {título}" |
| `oportunidad.ganada` / `oportunidad.perdida` | Portal | owner | "{OPP} marcada como Ganada/Perdida: {título}" |
| `actividad.asignada` | Push | owner (si ≠ actor) | "Nueva actividad asignada: {asunto}" |
| `lead.reasignado`, `oportunidad.reasignado`, `actividad.reasignado`, `cliente.reasignado` | Portal | nuevo asesor | "{Tipo} {code} reasignado a su cartera" |
| `postventa.detractor` | Email | responsable del cliente | "Detractor NPS n: {cliente} — caso {REC}" |
| `postventa.caso` | Portal | owner del caso (si ≠ actor) | "Caso {REC} asignado: {asunto}" |
| `postventa.cierre` | Email | cliente | "Su caso {REC} ha sido cerrado" |
| `cliente.comunicacion` | Email / WhatsApp / Portal | cliente | asunto libre (WhatsApp en sandbox) |

Plantillas y canales por evento son configurables en Administración › Notificaciones (PROC-059); los eventos CRM deben agregarse a esa matriz (hoy solo tiene eventos de cotización, reserva, pago, operación).

---

## 9. Auditoría (acciones críticas registradas)

Registro en `audit_logs`: actor, tenant, entidad, `entity_id` (code), acción, antes, después, fecha/hora, IP, correlación (PROC-063).

| Entidad | Acción | Antes → Después (como en el prototipo) |
|---|---|---|
| Lead | `crear` | fuente → estado (+ "duplicado de X") |
| Lead | `asignar` | responsable anterior / "sin asignar" → nuevo |
| Lead | `tomar_lead` | "sin asignar (cola comercial)" → asesor |
| Lead | `editar` | campos cambiados → "actualizado" |
| Lead | `primer_contacto` | nuevo → contactado |
| Lead | `cambio_estado` | estado → estado · comentario |
| Lead | `calificar` | "score / estado" anterior → nuevo |
| Lead | `descartar` | estado → motivo |
| Lead | `reabrir` | estado → nuevo |
| Lead | `marcar_duplicado` | estado → "duplicado de X" |
| Lead | `fusionar` | (origen) estado → "duplicado de Y"; (destino) "absorbe X"; (alta fusionada) "ingreso {fuente}" → "fusionado en X" |
| Lead | `convertir` | estado → "CLI + OPP" |
| Oportunidad | `crear` | origen / "desde LEAD" → etapa · monto |
| Oportunidad | `editar` | monto · prob → campos cambiados → monto · prob |
| Oportunidad | `cambio_etapa` | etapa → etapa (· motivo); también automáticos desde Cotizaciones |
| Oportunidad / Lead / Actividad / Cliente | `reasignar` | asesor → asesor · motivo |
| Actividad | `crear`, `completar`, `reprogramar` | — → tipo·fecha; pendiente → resultado (80 car.); fecha → fecha · motivo |
| Cliente | `crear`, `editar`, `agregar_contacto`, `contacto_principal`, `comunicacion`, `actualizar_nps`, `accion_fidelizacion` | — |
| Encuesta | `crear` | — → "NPS n · CLI" |
| Reclamo (caso) | `crear`, `cambio_estado`, `seguimiento` | tipo/encuesta → estado · prioridad; estado → estado · texto (60 car.) |
| Exportación | `exportar` | — → "n filas" |
| Acceso | `acceso_denegado` | rol → permiso requerido |

Consultas de auditoría en UI: historial del lead (audit + actividades completadas + "recibido"), de la oportunidad (oportunidad + lead + cotizaciones), del 360 (cliente + leads + oportunidades + cotizaciones + reservas + pagos + casos) y drawer de actividad.

---

## 10. Casos de prueba mínimos (Pest / Playwright)

| ID | Caso | Resultado esperado |
|---|---|---|
| CRM-T01 | Usuario de tenant A consulta lead/cliente de tenant B vía API (E2E-08) | 404/403; RLS sin filas |
| CRM-T02 | Operaciones, Finanzas o externo llama `GET /crm/leads` | 403 |
| CRM-T03 | Finanzas `GET /crm/clients/{code}` | 200; `PATCH` → 403 |
| CRM-T04 | Ventas U03 lista leads | solo propios + sin asignar; `GET` de LEAD-0231 (U04) → 403 `SCOPE_OWNERSHIP` |
| CRM-T05 | Ventas U03 crea lead enviando `owner_id=U04` | se guarda con owner U03 |
| CRM-T06 | Alta sin correo ni teléfono / correo inválido / teléfono 6 dígitos / fecha pasada | 422 |
| CRM-T07 | Alta con correo de CLI-0001 sin `duplicate_resolution` | 409 `CRM-BR-04` con coincidencia `client` |
| CRM-T08 | Resoluciones `merge`, `mark_duplicate`, `create_new` | merge no crea fila y suma +5 al score; mark crea `duplicado`; new crea `nuevo` con nota |
| CRM-T09 | Dos asesores toman el mismo lead en paralelo | uno 200, otro 409; una sola actividad de primer contacto; notificación a Managers |
| CRM-T10 | Ventas intenta `assign`/`reassign` | 403 |
| CRM-T11 | `status=calificado` con score 40 / `contactado` sin owner / `status=convertido` | 409 `CRM-BR-10` / 409 `CRM-BR-11` / 422 |
| CRM-T12 | BANT 25+25+18+10 con `mark_qualified` | score 78, estado calificado, fila en `crm_lead_qualifications` |
| CRM-T13 | Convertir lead `contactado` | 409 `CRM-BR-12` |
| CRM-T14 | Convertir lead calificado con cliente nuevo (DNI 7 dígitos; RUC que empieza en 30; empresa peruana con DNI; DNI existente) | 422 / 422 / 422 / 409 `CRM-BR-16` |
| CRM-T15 | Conversión válida repetida con el mismo `Idempotency-Key` | un solo cliente, una oportunidad, una reunión; lead `convertido` con vínculos |
| CRM-T16 | Descartar lead con 2 actividades pendientes | lead `descartado`, actividades `completada` con `auto_closed` |
| CRM-T17 | Mover oportunidad a `perdida` sin motivo / con "Otro" sin detalle | 422 |
| CRM-T18 | Ganar oportunidad sin cotización aprobada | 200 con `meta.warnings`; prob 100, `closed_at` hoy |
| CRM-T19 | Reabrir oportunidad perdida desde `cotizacion` | vuelve a `cotizacion`, prob 55, motivo limpio |
| CRM-T20 | Crear cotización con `opportunity_id` de oportunidad en descubrimiento; registrar negociación; convertir | etapas cotizacion → negociacion → ganada, auditadas |
| CRM-T21 | Crear oportunidad con monto 0 o cierre pasado | 422 |
| CRM-T22 | Completar actividad Llamada de lead `nuevo` con próxima | actividad completada, nueva pendiente con `previous_activity_id`, lead `contactado` |
| CRM-T23 | Completar sin resultado / Ventas completa actividad ajena | 422 / 403 |
| CRM-T24 | JOB-04 con actividad de ayer pendiente | pasa a `vencida` y se notifica |
| CRM-T25 | Encuesta NPS 3 sobre reserva completada | encuesta + caso `alta` + actividad Postventa mañana 10:00 + Email; `nps_last=3` |
| CRM-T26 | Segunda encuesta a la misma reserva | 409 `CRM-BR-41` |
| CRM-T27 | Caso: resolver sin texto; cerrar desde `abierta`; Felicitación | 422; 409; nace `cerrada` |
| CRM-T28 | Ventas en 360 de cliente compartido | solo sus oportunidades/cotizaciones/reservas/CxC; `partial_view=true` |
| CRM-T29 | Exportación CSV de leads con rol Ventas | solo su alcance; audit `exportar` |
| CRM-T30 | Toda acción de §9 | fila en `audit_logs` con antes/después |
| E2E-01 (Playwright) | Lead → tomar → actividad completada → BANT → convertir → oportunidad → crear cotización → aprobación | Flujo completo sin errores, con historial visible en 360 |
| CRM-E2E-02 | Kanban: drag de tarjeta a "Perdida" abre modal de motivo; en 400 px se usa el menú ⋯ | Estado persistido, sin scroll horizontal de página |
| CRM-E2E-03 | Panel de Ventas U03: "Tomar" de la bolsa y "Hecha" | mismo efecto que en CRM (actividad de primer contacto, cambio a contactado) |

---

## 11. Cambios y decisiones respecto a la documentación inicial

| # | Tipo | Tema | Documentación inicial | Especificación (prototipo) | Motivo |
|---|---|---|---|---|---|
| 1 | Agregado | Estados del lead | No define estados | `nuevo, contactado, calificado, convertido, duplicado, descartado` con máquina §4.1 | Necesario para cola, embudo y trazabilidad |
| 2 | Precisado | PROC-004 Entradas | nombre, contacto, empresa, fuente, interés, destino, producto, fechas, pasajeros, canal | Todos presentes; producto limitado a ACTIVE/APPROVED; destino desde jerarquía de destinos o "Varios"; se agregan `score`, `notes`, trazas de validación/asignación | Integración con Catálogo (PRD-BR-002) |
| 3 | Precisado | PROC-004 Validar | Sin reglas | Correo o teléfono obligatorio; formato de correo; teléfono ≥ 7 dígitos; fecha ≥ hoy (CRM-BR-01..05) | Evitar leads no contactables |
| 4 | Precisado | PROC-004 Detectar duplicado | Sin criterio | Por correo normalizado o últimos 9 dígitos del teléfono, contra leads **y clientes**; 3 resoluciones (fusionar, marcar duplicado, crear igual) + fusión posterior desde la ficha | Evitar doble atención y duplicar fichas de cliente |
| 5 | Agregado | PROC-004 Asignar | "Asignar" genérico | Cola comercial (leads sin owner) + "Tomar lead" atómico por Ventas + asignación/reasignación solo por supervisor + primer contacto automático a +2 h + aviso a gerencia | Modelo de mercado (bolsa común tipo HubSpot/Salesforce) y control de gerencia |
| 6 | Precisado | PROC-004 Primer contacto | Paso sin disparador | Se registra al completar una actividad de contacto (Llamada/Email/WhatsApp/Reunión) o al cambiar estado manualmente | Trazabilidad real del contacto |
| 7 | Agregado | PROC-005 Calificar | Sin método | BANT (4 × 0/10/18/25 = score 0–100), umbral 50 para `calificado`, historial de calificaciones | Criterio objetivo y medible |
| 8 | Precisado | PROC-005 Conversión | "Lead → Contacto/Cliente + Oportunidad, conservar historial" | Transacción única e idempotente; cliente nuevo o vinculación a existente; el "Contacto" se modela como contacto del cliente (`crm_client_contacts`), no como entidad separada; lead queda `convertido` con enlaces | Evitar un tercer maestro (contactos) duplicado de clientes; cumplir "conservar historial" |
| 9 | Precisado | "Empresas" (mapa Comercial) | Entidad listada | Cliente con `kind = empresa`, RUC obligatorio si es peruana, con contactos | Un único maestro de clientes (persona/empresa) |
| 10 | Agregado | Validación documental temprana | RT-10 solo en facturación | DNI/RUC/país validados al crear cliente; documento único por tenant | Evitar rechazos en facturación y duplicados |
| 11 | Precisado | PROC-006 Etapas | Crear → Asignar → Calificar → Descubrir → Seguimiento → Cotización → Negociación → Won/Lost | Etapas persistidas: Calificación (20 %), Descubrimiento (35 %), Cotización (55 %), Negociación (70 %), Ganada (100 %), Perdida (0 %), configurables por tenant; "Crear/Asignar/Seguimiento" son acciones, no etapas | Pipeline estándar con probabilidad ponderada |
| 12 | Precisado | PROC-006 Cierre perdido | "requiere motivo" | Catálogo de motivos + detalle obligatorio para "Otro"; se guarda la etapa de pérdida | Análisis de pérdidas (reportes) |
| 13 | Agregado | Reapertura | No contemplada | Se permite reabrir ganadas/perdidas con confirmación y auditoría | Casos reales (cliente vuelve) |
| 14 | Decisión | Ganada sin cotización aprobada | No definido | Permitido con advertencia (no bloquea) | Ventas cerradas fuera del flujo de cotización; ver §12 para endurecer |
| 15 | Agregado | Sincronización con Cotizaciones | CRM → Quote como handoff | La oportunidad avanza automáticamente (cotizacion / negociacion / ganada) según eventos de la cotización | Evitar doble registro y pipeline desactualizado |
| 16 | Precisado | PROC-007 Actividades | Crear → Asignar → Programar → Ejecutar → Resultado → Próxima → Completar | Tipos fijos (Llamada, Email, WhatsApp, Reunión, Tarea, Postventa); resultado obligatorio; próxima actividad sugerida (+2 días 10:00); reprogramar; vencida por job; vínculo a lead/oportunidad/cliente/cotización | Operativizar el proceso |
| 17 | Agregado | Responsables de Operaciones | No definido | Supervisor puede asignar actividades a usuarios de Operaciones | Tareas de soporte (p. ej. confirmar cupos) |
| 18 | Precisado | PROC-008 Customer 360 | 13 elementos a consolidar | 12 pestañas + 5 KPIs + alertas (saldo vencido, casos abiertos); comunicaciones = notificaciones enviadas; documentos = comprobantes + vouchers + propuestas; historial = línea de tiempo + auditoría | Cubrir todos los elementos listados |
| 19 | Agregado | Alcance Ventas en el 360 | No definido | Cartera = clientes propios + con registros propios; vista parcial dentro del 360 | Principio de ownership (BRIEF_ROLES) |
| 20 | Agregado | Acceso de Finanzas/Contabilidad al 360 | No definido | Solo lectura vía `finance.receivables` | Gestión de cobranza con contexto del cliente |
| 21 | Precisado | PROC-009 Postventa | Viaje completado → Contactar → Experiencia → Reclamo → Resolver → 360 | Lista de viajes a cerrar (60 d / próximos 5 d), encuesta NPS 0–10, una por reserva, caso automático con NPS ≤ 6, ciclo de casos con bitácora, NPS visible en 360 | Medición estándar (NPS) y cierre de ciclo |
| 22 | Precisado | PROC-010 Fidelización | Viaje cerrado → Segmentación → Acción → Nueva oportunidad | Segmentación por segmento/recencia/destino; acción = oportunidad de recompra con propuesta sugerida y origen "Fidelización" | Operativizar el proceso sin motor de campañas (Marketing es otro módulo) |
| 23 | Agregado | Panel de Ventas | Dashboards genéricos (PROC-060) | Panel "Mi cartera" §7.4 con comisión estimada y bolsa de leads | Experiencia por rol |
| 24 | Agregado | Permiso de reasignación | — | Prototipo usa rol (`tenant_admin`/`manager`) + `crm.leads.manage`; se propone permiso explícito `crm.records.reassign` asignado a esos roles | Autorización por permiso (spatie), no por nombre de rol |
| 25 | Cambiado | Unificación panel ↔ CRM | — | En el prototipo, "Tomar" del panel no crea actividad de primer contacto ni avisa a gerencia (audita `asignar`), y "Hecha" guarda `doneAt`, no ofrece próxima actividad ni pasa el lead a contactado. **La especificación unifica**: el panel llama a los endpoints del CRM | Una sola regla de negocio (RT-18/19) |
| 26 | Cambiado | Fecha de cierre al ganar por conversión | — | Prototipo (ventas.js) sobrescribe `closeDate`; se usa `closed_at` y se conserva `expected_close_date` | Consistencia con cierre manual y KPIs |
| 27 | Cambiado | Comunicaciones del 360 | — | Prototipo busca notificaciones por coincidencia de texto (correo, teléfono, nombre, códigos); se especifica FK `client_id`/`related_*` en `notif_messages` | Exactitud y rendimiento |
| 28 | Precisado | Estado `vencida` de actividad | — | Prototipo lo deriva en UI (`due < ahora`) y la semilla lo guarda; se persiste por JOB-04 y la UI puede seguir derivándolo | Filtros y KPIs consistentes en backend |
| 29 | Agregado | Catálogos configurables | Constantes | `crm_catalog_items` por tenant (fuentes, canales, intereses, segmentos, motivos, tipos de caso, canales de encuesta) | Multi-tenant configurable (PROC-003) |

---

## 12. Supuestos y puntos a validar con negocio

| # | Punto | Supuesto actual / propuesta |
|---|---|---|
| 1 | Umbral de calificación (50) y pesos BANT | Fijos como en el prototipo; ¿configurables por tenant? |
| 2 | Probabilidades por etapa | Semilla 20/35/55/70/100/0; ¿el gerente puede editarlas y agregar etapas? (el CHECK de `stage` lo impediría; alternativa: FK a `crm_pipeline_stages`) |
| 3 | Ganar sin cotización aprobada | Hoy solo advierte. ¿Bloquear (RT-3 estricto) o exigir aprobación de gerencia? |
| 4 | Reapertura de oportunidades | Cualquier dueño con `crm.opportunities.manage`. ¿Restringir a supervisor? |
| 5 | Formato de códigos | `LEAD-0232` (sin año, 4 dígitos). ¿Adoptar `LEAD-2026-00001` como cotizaciones? |
| 6 | Actividades de usuarios de Operaciones | Pueden ser responsables pero no ven la agenda CRM (sin `crm.activities.manage`). Decidir: mostrarlas en su panel/bandeja, o no permitir asignarlas |
| 7 | Reprogramar a fecha pasada | El prototipo lo permite; se propone rechazar `due_at < now()` |
| 8 | Monto en conversión | Convertir acepta monto 0 (solo `required`); alta manual exige > 0. Se propone > 0 en ambos |
| 9 | Alcance en "Viajes por cerrar" | En el prototipo, por precedencia de operadores, las reservas `en_operacion` no se filtran por cartera/asesor. El backend debe aplicar alcance a ambas condiciones (asumido) |
| 10 | Búsqueda global | En el prototipo, Ventas ve clientes ajenos en la búsqueda global (luego se le deniega el detalle). El backend debe aplicar CRM-BR-33 también en búsqueda |
| 11 | Postventa vs Libro de reclamaciones | Los casos de postventa (`crm_cases`, permiso `crm.clients.manage`) son distintos del Libro de Reclamaciones INDECOPI (`crm.claims.manage`, otra especificación). ¿Un reclamo del libro debe crear/enlazar un caso? |
| 12 | Disparo de postventa | No hay automatismo al completar un viaje; se propone listener `ReservationCompleted` → actividad Postventa al owner (+ encuesta por portal) |
| 13 | Botón de recompra | Visible con `crm.clients.manage` pero requiere `crm.opportunities.manage` (hoy coinciden en todos los roles). Backend valida el segundo |
| 14 | NPS del cliente | Se sobrescribe con la última encuesta; clientes sin encuesta conservan el NPS de la migración. ¿Mostrar promedio o último? |
| 15 | Tipo de cambio en KPIs | KPIs en PEN con el TC vigente, no el de cada operación. ¿Aceptable para gerencia? |
| 16 | Comisión estimada del panel | Criterio: reservas con inicio de viaje en el mes. Confirmar con Finanzas (¿fecha de venta o de viaje?) |
| 17 | Duplicados por teléfono | Últimos 9 dígitos (formato Perú). Para extranjeros puede dar falsos positivos/negativos; ¿normalizar E.164? |
| 18 | Documentos CE y Pasaporte | Sin validación de formato; ¿reglas mínimas? |
| 19 | Calificación de lead sin owner por supervisor | El supervisor queda como owner automáticamente. ¿Correcto o debe exigir asignación previa? |
| 20 | Datos personales (Ley 29733) | El prototipo no registra consentimiento ni base legal del lead; se asume que se agregará `consent_at`/`consent_source` si negocio lo requiere (formularios web) |
| 21 | Exportación CSV | Hoy cualquier rol con acceso a la tabla exporta; ¿exigir `reports.export`? |
| 22 | Ingreso automático de leads | El prototipo simula un lead de sistema en la cola (Formulario web). Canales reales (web, WhatsApp, Facebook Lead Ads) se integrarán vía adapters/Inbox; se asume `created_by = NULL` y misma validación/duplicados |
| 23 | Metas comerciales | `crm/metas` está en el menú y en permisos pero no implementado en `crm.js`; se especifica aparte |
| 24 | Plantillas de comunicación | "Enviar comunicación" es texto libre; ¿plantillas por evento y registro de respuesta (inbox omnicanal)? |