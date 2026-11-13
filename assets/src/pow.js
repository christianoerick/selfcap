// Proof-of-work search: find a nonce where SHA-256(salt + nonce) starts with
// `difficulty` zero bits. This function is serialized with toString() to run
// inside Web Workers, so it must not reference anything outside itself.
export function powCore() {
  var K = new Int32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ])
  var W = new Int32Array(64)

  // First 32 bits of SHA-256 for an ASCII message of up to 55 bytes (one block).
  function firstWord(message) {
    var i
    var length = message.length

    for (i = 0; i < 16; i++) W[i] = 0
    for (i = 0; i < length; i++) W[i >> 2] |= message.charCodeAt(i) << (24 - (i & 3) * 8)
    W[length >> 2] |= 0x80 << (24 - (length & 3) * 8)
    W[15] = length * 8

    for (i = 16; i < 64; i++) {
      var w15 = W[i - 15]
      var w2 = W[i - 2]
      var s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3)
      var s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10)
      W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0
    }

    var a = 0x6a09e667 | 0
    var b = 0xbb67ae85 | 0
    var c = 0x3c6ef372 | 0
    var d = 0xa54ff53a | 0
    var e = 0x510e527f | 0
    var f = 0x9b05688c | 0
    var g = 0x1f83d9ab | 0
    var h = 0x5be0cd19 | 0

    for (i = 0; i < 64; i++) {
      var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7))
      var t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0
      var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10))
      var t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0
      h = g
      g = f
      f = e
      e = (d + t1) | 0
      d = c
      c = b
      b = a
      a = (t1 + t2) | 0
    }

    return (0x6a09e667 + a) | 0
  }

  // Tries `count` nonces starting at `start`, `step` apart. Returns the nonce or -1.
  function search(salt, difficulty, start, step, count) {
    var nonce = start

    for (var i = 0; i < count; i++, nonce += step) {
      if (Math.clz32(firstWord(salt + nonce)) >= difficulty) return nonce
    }

    return -1
  }

  search.firstWord = firstWord

  return search
}
