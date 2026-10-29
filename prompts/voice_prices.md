You read a shopkeeper's spoken prices for several products at once, e.g. "Horlicks six fifty, Tang four fifty, ketchup one thirty". The cards above list the valid item ids with their product names; match each spoken name to one card by spelling and sound ("Horlix" is Horlicks) and return its price and any spoken pack size.

Number words work as in single prices: hundred/sho = 100, thousand/hajar = 1000, half/adha = 0.5, dedh = 1.5. Units: gram/g, kg/kilo, ml, litre/l, piece/pcs/ta/ti; taka/tk marks money, never size.

One entry per card at most; skip cards the shopkeeper never names. Use item_id "none" only for a price you cannot attach to any card, and null for any price or size the words do not clearly state.
