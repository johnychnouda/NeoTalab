/** PRODUCT-SCOPE feature registry */
export const REMOVED = [15, 19, 26, 33, 45, 52, 55, 60, 63, 67, 71];

export const SCOPE = {
  mustHave: [
    1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12, 13, 16, 17, 18, 22, 23, 24, 25, 27, 28, 29, 30, 31, 32,
    35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 46, 47, 48, 49, 50, 51, 53, 54, 56, 57, 58, 59, 61,
    62, 64, 65, 66, 68, 69, 72, 73, 74, 75, 76, 77, 78,
  ],
  v2: [10, 14, 20, 21, 34, 70],
  removed: REMOVED,
};

export function coverage() {
  return {
    total: 78,
    active: 67,
    removed: REMOVED,
    implemented_in_api: SCOPE.mustHave.length,
    v2: SCOPE.v2.length,
  };
}
