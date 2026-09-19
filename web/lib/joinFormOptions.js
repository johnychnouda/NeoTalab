/** Stable API values with i18n label keys for the public join form. */
export const JOIN_BUSINESS_TYPES = [
  { value: "Restaurant / Café", labelKey: "join.btype.restaurant" },
  { value: "Bakery / Sweets", labelKey: "join.btype.bakery" },
  { value: "Supermarket / Grocery", labelKey: "join.btype.grocery" },
  { value: "Boutique / Clothing", labelKey: "join.btype.boutique" },
  { value: "Pharmacy", labelKey: "join.btype.pharmacy" },
  { value: "Electronics", labelKey: "join.btype.electronics" },
  { value: "Flower Shop", labelKey: "join.btype.flower" },
  { value: "Pet Shop", labelKey: "join.btype.pet" },
  { value: "Home & Furniture", labelKey: "join.btype.furniture" },
  { value: "General Retail", labelKey: "join.btype.retail" },
  { value: "Other", labelKey: "join.btype.other" },
];

export const JOIN_BUSINESS_TYPE_OTHER = "Other";

export const JOIN_CITY_OTHER = "Other";

export const JOIN_LEBANON_REGIONS = [
  { value: "Beirut", labelKey: "join.region.beirut" },
  { value: "Mount Lebanon", labelKey: "join.region.mountLebanon" },
  { value: "North Lebanon", labelKey: "join.region.north" },
  { value: "South Lebanon", labelKey: "join.region.south" },
  { value: "Bekaa", labelKey: "join.region.bekaa" },
  { value: "Nabatieh", labelKey: "join.region.nabatieh" },
  { value: "Akkar", labelKey: "join.region.akkar" },
  { value: "Baalbek-Hermel", labelKey: "join.region.baalbek" },
];

/** Cities keyed by JOIN_LEBANON_REGIONS value. */
export const JOIN_LEBANON_CITIES_BY_REGION = {
  Beirut: [
    { value: "Beirut", labelKey: "join.city.beirut" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  "Mount Lebanon": [
    { value: "Baabda", labelKey: "join.city.baabda" },
    { value: "Aley", labelKey: "join.city.aley" },
    { value: "Jounieh", labelKey: "join.city.jounieh" },
    { value: "Byblos", labelKey: "join.city.byblos" },
    { value: "Broummana", labelKey: "join.city.broummana" },
    { value: "Bikfaya", labelKey: "join.city.bikfaya" },
    { value: "Beit Mery", labelKey: "join.city.beitMery" },
    { value: "Dbayeh", labelKey: "join.city.dbayeh" },
    { value: "Jbeil", labelKey: "join.city.jbeil" },
    { value: "Chouf", labelKey: "join.city.chouf" },
    { value: "Deir el Qamar", labelKey: "join.city.deirElQamar" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  "North Lebanon": [
    { value: "Tripoli", labelKey: "join.city.tripoli" },
    { value: "Zgharta", labelKey: "join.city.zgharta" },
    { value: "Batroun", labelKey: "join.city.batroun" },
    { value: "Koura", labelKey: "join.city.koura" },
    { value: "Bsharri", labelKey: "join.city.bsharri" },
    { value: "Miniyeh-Danniyeh", labelKey: "join.city.minieh" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  "South Lebanon": [
    { value: "Sidon", labelKey: "join.city.sidon" },
    { value: "Tyre", labelKey: "join.city.tyre" },
    { value: "Jezzine", labelKey: "join.city.jezzine" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  Bekaa: [
    { value: "Zahle", labelKey: "join.city.zahle" },
    { value: "Chtaura", labelKey: "join.city.chtaura" },
    { value: "Joub Jannine", labelKey: "join.city.joubJannine" },
    { value: "Rashaya", labelKey: "join.city.rashaya" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  Nabatieh: [
    { value: "Nabatieh", labelKey: "join.city.nabatiehCity" },
    { value: "Bint Jbeil", labelKey: "join.city.bintJbeil" },
    { value: "Marjeyoun", labelKey: "join.city.marjeyoun" },
    { value: "Hasbaya", labelKey: "join.city.hasbaya" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  Akkar: [
    { value: "Halba", labelKey: "join.city.halba" },
    { value: "Qoubaiyat", labelKey: "join.city.qoubaiyat" },
    { value: "Other", labelKey: "join.city.other" },
  ],
  "Baalbek-Hermel": [
    { value: "Baalbek", labelKey: "join.city.baalbekCity" },
    { value: "Hermel", labelKey: "join.city.hermel" },
    { value: "Other", labelKey: "join.city.other" },
  ],
};

export function getLebanonCitiesForRegion(region) {
  return JOIN_LEBANON_CITIES_BY_REGION[region] || [];
}
