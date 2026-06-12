\# LASTELLA PMS – PHASE 2.2 BOOKING MANAGEMENT UI



\## Objective



Implement Booking Management UI for the existing Phase 2.1 Booking Engine.



Current completed commits:



\* f225310 Phase 1 Core PMS completed

\* 40d31a4 Phase 2.1 Booking Engine Foundation

\* 554ce8d Add Phase 2 booking engine specification



This phase must implement UI only for managing bookings using the backend foundation already created.



Do NOT implement Timeline UI.



Do NOT implement Room Map UI.



Do NOT implement drag/drop.



Do NOT implement OTA.



Do NOT implement payment gateway.



\---



\# Required UI Modules



\## 1. Booking List Page



Create a page to list bookings.



Route suggestion:



```text

/admin/bookings

```



Show columns:



\* booking\_code

\* customer\_name

\* customer\_phone

\* booking\_type

\* checkin\_at

\* checkout\_at

\* adults

\* children\_under\_6

\* children\_over\_6

\* status

\* sales\_user

\* booking\_color

\* created\_at



Required filters:



\* booking\_code

\* customer\_name

\* customer\_phone

\* status

\* booking\_type

\* checkin date range

\* checkout date range

\* sales\_user\_id



Required actions:



\* View

\* Edit

\* Cancel

\* Add Requirement

\* Add Deposit

\* Assign Room



Use server-side pagination.



\---



\## 2. Booking Create Page



Route suggestion:



```text

/admin/bookings/create

```



Fields:



\* customer\_name

\* customer\_phone

\* customer\_email

\* customer\_type

\* booking\_type

\* checkin\_at

\* checkout\_at

\* adults

\* children\_under\_6

\* children\_over\_6

\* booking\_color

\* sales\_user\_id

\* note

\* internal\_note



Rules:



\* checkin\_at required

\* checkout\_at required

\* checkout\_at must be after checkin\_at

\* customer\_name required

\* booking\_type required

\* customer\_type required

\* booking\_color required



After create:



\* Redirect to Booking Detail page

\* Allow adding room requirements



\---



\## 3. Booking Edit Page



Route suggestion:



```text

/admin/bookings/{booking}/edit

```



Allow editing basic booking information.



Do not allow editing if status is:



\* CHECKED\_OUT

\* CANCELLED

\* NO\_SHOW



\---



\## 4. Booking Detail Page



Route suggestion:



```text

/admin/bookings/{booking}

```



Use tab layout:



\### Tab 1: Overview



Show:



\* booking\_code

\* customer info

\* booking type

\* checkin\_at

\* checkout\_at

\* adults

\* children

\* status

\* booking color

\* sales user

\* notes



\### Tab 2: Requirements



Show booking room requirements.



Columns:



\* room\_type

\* quantity

\* adults

\* children\_under\_6

\* children\_over\_6

\* room\_price

\* price\_source

\* note



Actions:



\* Add Requirement

\* Edit Requirement

\* Delete Requirement



\### Tab 3: Payments / Deposits



Show booking payments.



Columns:



\* payment\_type

\* amount

\* payment\_method

\* payment\_at

\* confirmed\_by

\* note



Actions:



\* Add Deposit

\* Add Additional Deposit

\* Add Refund

\* Add Adjustment



\### Tab 4: Room Assignments



Show assigned rooms.



Columns:



\* room\_number

\* room\_type

\* start\_at

\* end\_at

\* status

\* assigned\_by

\* released\_at

\* release\_reason



Actions:



\* Assign Room

\* Release Assignment



Do not implement room map here.



Use dropdown/select list for rooms.



\### Tab 5: Stays



Show stays.



Columns:



\* room\_number

\* planned\_checkin\_at

\* planned\_checkout\_at

\* actual\_checkin\_at

\* actual\_checkout\_at

\* status



Actions:



\* Check In

\* Check Out



Only implement simple button-based action.



No timeline UI.



\---



\# Room Requirement UI



\## Add Requirement Form



Fields:



\* room\_type\_id

\* quantity

\* adults

\* children\_under\_6

\* children\_over\_6

\* room\_price

\* price\_source

\* note



Validation:



\* room\_type\_id required

\* quantity >= 1

\* room\_price >= 0

\* price\_source required



After saving:



\* Return to booking detail requirements tab



\---



\# Payment UI



\## Add Payment Form



Fields:



\* payment\_type

\* amount

\* payment\_method

\* payment\_at

\* note



Validation:



\* payment\_type required

\* amount > 0

\* payment\_method required

\* payment\_at required



After saving:



\* Return to booking detail payments tab



\---



\# Room Assignment UI



\## Assign Room Form



Fields:



\* room\_id

\* start\_at

\* end\_at



Validation:



\* room\_id required

\* start\_at required

\* end\_at required

\* end\_at must be after start\_at



Business rule:



\* Must call existing RoomAssignmentService

\* Must block overlapping room assignment by datetime

\* Must show clear validation message if room conflict exists



Important:



Do not duplicate conflict logic in Vue.



Conflict validation must happen in Laravel service/backend.



\---



\# Stay UI



\## Check In



Allow check-in from Booking Detail → Stays tab.



User can check in one stay at a time.



Use current datetime as default actual\_checkin\_at.



\## Check Out



Allow check-out from Booking Detail → Stays tab.



User can check out one stay at a time.



Use current datetime as default actual\_checkout\_at.



\---



\# Permissions



Use existing RBAC.



Required permission checks:



\* booking.create

\* booking.update

\* booking.cancel

\* room.assign

\* room.unassign

\* stay.checkin

\* stay.checkout

\* payment.create



Hide UI actions when user has no permission.



Backend must still enforce policy.



\---



\# Frontend Requirements



Use existing stack:



\* Vue 3

\* InertiaJS

\* TailwindCSS

\* Existing AppLayout

\* Existing reusable CRUD components if suitable



Do not introduce a heavy UI library.



Keep UI simple, clean, and functional.



\---



\# Backend Requirements



Create or update:



\* BookingController

\* BookingRequirementController

\* BookingPaymentController

\* RoomAssignmentController

\* StayController



Use Form Request validation.



Controllers must remain thin.



Business logic must remain in services.



\---



\# Tests Required



Add feature tests:



\* admin can view booking list

\* admin can create booking

\* admin can view booking detail

\* admin can add requirement

\* admin can add deposit

\* admin can assign available room

\* admin cannot assign conflicting room

\* admin can release assignment

\* admin can check in stay

\* admin can check out stay

\* user without permission cannot create booking



Existing tests must still pass.



\---



\# Completion Criteria



Run:



```bash

php artisan migrate:fresh --seed

php artisan test

npm run build

```



All must pass.



Report:



\* created files

\* modified files

\* routes added

\* tests added

\* test result

\* build result



Create git commit:



```text

Phase 2.2 Booking Management UI

```



