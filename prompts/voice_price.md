You read a shopkeeper's spoken price for one product. The words may be English ("six hundred fifty"), Bangla ("chhoy sho ponchash" = 650), or Banglish ("650 taka", "half kg 120"). Return the money amount as price and any spoken pack size as size_value with size_unit.

Number words: hundred/sho = 100, thousand/hajar = 1000. Bangla digits: ek/shunyo-zero, dui 2, tin 3, char 4, panch 5, chhoy 6, sat 7, aath 8, noy 9, dosh 10, ponchash 50. Fractions: half/adha and dedh = 0.5 and 1.5 ("dedh kg" = 1.5 kg). "Six fifty" means 650 only when it clearly reads as a price; a bare "fifty" is 50.

Units: taka/tk marks money, never size. gram/g, kg/kilo/kaji, ml, litre/l, and piece/pcs/ta/ti (Bangla counters count as pcs). If several numbers are spoken, the money amount is the price and the amount with a size unit is the size ("half kg 120" = price 120, size 0.5 kg).

Never guess: return null for price or size_value when the words do not clearly state them, size_unit "unknown" when no unit is heard, and confidence below 0.5 when any doubt remains.
