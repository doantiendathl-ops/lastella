\# LASTELLA PMS – PHASE 2.1 BOOKING ENGINE FOUNDATION



\## Objective



Implement the backend foundation for the Booking Engine of Lastella PMS.



Current status:



\* Phase 1 completed

\* Laravel 12 running successfully

\* Tests passing

\* Floors, Room Types, Rooms, Rates, RBAC already exist

\* Git checkpoint:



&#x20; \* Commit: f225310

&#x20; \* Message: Phase 1 Core PMS completed



This phase must only implement Booking Engine backend foundation.



Do NOT implement Timeline UI.



Do NOT implement Room Map UI.



Do NOT implement OTA integration.



Do NOT implement Payment Gateway integration.



\---



\# Existing Business Context



The hotel sells:



\* Overnight stays

\* Hourly stays

\* Group bookings

\* Individual bookings



A booking may contain:



\* Multiple room types

\* Multiple rooms

\* Multiple guests



Room assignment happens after booking creation.



Deposits are optional.



Multiple deposit transactions are allowed.



Room conflicts must be checked by datetime, not date only.



Timeline will later operate on hourly precision.



\---



\# Required Tables



\## bookings



Fields:



\* id

\* booking\_code

\* booking\_color

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

\* status

\* sales\_user\_id

\* created\_by

\* updated\_by

\* cancelled\_at

\* cancelled\_by

\* cancellation\_reason

\* note

\* internal\_note

\* timestamps



\---



\## booking\_requirements



Fields:



\* id

\* booking\_id

\* room\_type\_id

\* quantity

\* adults

\* children\_under\_6

\* children\_over\_6

\* room\_price

\* price\_source

\* note

\* timestamps



\---



\## booking\_payments



Fields:



\* id

\* booking\_id

\* payment\_type

\* amount

\* payment\_method

\* payment\_at

\* confirmed\_by

\* note

\* timestamps



\---



\## room\_assignments



Fields:



\* id

\* booking\_id

\* room\_id

\* room\_type\_id

\* start\_at

\* end\_at

\* status

\* assigned\_by

\* released\_by

\* released\_at

\* release\_reason

\* timestamps



\---



\## stays



Fields:



\* id

\* booking\_id

\* room\_assignment\_id

\* room\_id

\* planned\_checkin\_at

\* planned\_checkout\_at

\* actual\_checkin\_at

\* actual\_checkout\_at

\* status

\* checked\_in\_by

\* checked\_out\_by

\* note

\* timestamps



\---



\# Required Enums



\## BookingStatus



\* DRAFT

\* PENDING\_ASSIGNMENT

\* PARTIALLY\_ASSIGNED

\* FULLY\_ASSIGNED

\* HELD

\* DEPOSITED

\* PARTIALLY\_CHECKED\_IN

\* CHECKED\_IN

\* PARTIALLY\_CHECKED\_OUT

\* CHECKED\_OUT

\* CANCELLED

\* NO\_SHOW



\---



\## BookingType



\* OVERNIGHT

\* DAY\_USE

\* HOURLY

\* EARLY\_CHECKIN

\* LATE\_CHECKOUT



\---



\## CustomerType



\* INDIVIDUAL

\* GROUP

\* COMPANY

\* TOUR

\* WALK\_IN



\---



\## PriceSource



\* RATE\_TABLE

\* MANUAL

\* SPECIAL\_DEAL



\---



\## PaymentType



\* DEPOSIT

\* ADDITIONAL\_DEPOSIT

\* ROOM\_PAYMENT

\* SERVICE\_PAYMENT

\* REFUND

\* ADJUSTMENT



\---



\## AssignmentStatus



\* ASSIGNED

\* RELEASED

\* CHECKED\_IN

\* CHECKED\_OUT

\* CANCELLED

\* NO\_SHOW



\---



\## StayStatus



\* RESERVED

\* CHECKED\_IN

\* CHECKED\_OUT

\* CANCELLED

\* NO\_SHOW



\---



\# Relationships



Booking



hasMany:



\* bookingRequirements

\* bookingPayments

\* roomAssignments

\* stays



BookingRequirement



belongsTo:



\* booking

\* roomType



BookingPayment



belongsTo:



\* booking



RoomAssignment



belongsTo:



\* booking

\* room



Stay



belongsTo:



\* booking

\* roomAssignment

\* room



\---



\# Services Required



\## BookingService



Methods:



\* createBooking()

\* updateBooking()

\* cancelBooking()

\* updateBookingAssignmentStatus()

\* updateBookingStayStatus()



\---



\## BookingPaymentService



Methods:



\* addDeposit()

\* addPayment()

\* addRefund()



\---



\## RoomAssignmentService



Methods:



\* assignRooms()

\* releaseAssignment()

\* checkRoomConflict()

\* getAssignmentSummary()



Room conflict rule:



A room cannot be assigned if:



existing.room\_id = new.room\_id



AND



existing.status IN

(ASSIGNED, CHECKED\_IN)



AND



existing.start\_at < new.end\_at



AND



existing.end\_at > new.start\_at



Must use datetime precision.



\---



\## StayService



Methods:



\* createStayFromAssignment()

\* checkIn()

\* checkOut()

\* checkInMany()

\* checkOutMany()



\---



\# Business Rules



\## Partial Assignment



If booking requires:



5 Twin



but only:



3 Twin assigned



Status:



PARTIALLY\_ASSIGNED



\---



\## Full Assignment



If all required rooms are assigned:



Status:



FULLY\_ASSIGNED



\---



\## Deposits



Multiple deposits allowed.



Never overwrite historical payment records.



Create new payment records instead.



\---



\## Booking Color



Store booking\_color.



Validation only.



No UI implementation required.



\---



\# Policies



Create policies for:



\* Booking

\* BookingPayment

\* RoomAssignment

\* Stay



Reuse existing RBAC approach.



\---



\# Feature Tests Required



Must implement tests:



\* booking can be created

\* booking code auto generated

\* booking can have multiple requirements

\* booking can store manual room price

\* booking can record deposit

\* room conflict detection works

\* room assignment succeeds when no conflict exists

\* booking becomes partially assigned

\* booking becomes fully assigned

\* stay can be created from assignment

\* stay can check in

\* stay can check out



All tests must pass.



\---



\# Deliverables



Implement:



\* migrations

\* enums

\* models

\* relationships

\* services

\* policies

\* requests

\* factories

\* seeders if needed

\* tests



Do NOT implement:



\* Timeline UI

\* Room Map UI

\* OTA

\* Payment Gateway



\---



\# Validation



Before completion run:



composer install

php artisan migrate:fresh --seed

php artisan test

npm run build



Report:



\* files created

\* files modified

\* test results

\* migration results



Commit message:



Phase 2.1 Booking Engine Foundation



