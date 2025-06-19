#include <Wire.h>                      // ← Add this
#include <WiFi.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <DHT.h>

// ---------- PIN DEFINITIONS ----------
#define TRIG_PIN    18
#define ECHO_PIN    19
#define LEAK_PIN    34
#define RELAY_PIN   5
#define DHT_PIN     4
#define DHT_TYPE    DHT11

// ---------- OLED SETUP ----------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 32
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

// Only redraw when something really changes
bool displayDirty = false;

// ---------- SCROLLING TEXT STRUCTURE ----------
struct ScrollLine {
  String text;
  int y;
  int offset;
  int scrollDir;
  int scrollRange;
  int scrollSpeed;
  unsigned long lastReverse;
  bool waiting;
};
ScrollLine lines[4];
unsigned long lastScrollUpdate = 0;
const int SCROLL_INTERVAL = 30;

// ---------- WIFI VIA PREFERENCES ----------
Preferences preferences;
#define MAX_WIFI 3
String ssidList[MAX_WIFI], passList[MAX_WIFI], currentSSID;

// ---------- DHT SENSOR ----------
DHT dht(DHT_PIN, DHT_TYPE);

// ---------- TIMING & STATE ----------
unsigned long lastPost = 0;
const unsigned long POST_INTERVAL = 1000;
int lastRelayState = LOW;
float lastDistValue = -1;
unsigned long stableSince = 0;

// ---------- FUNCTION PROTOTYPES ----------
void displayStatus(const String &msg);
void loadWiFiCredentials();
void connectToWiFi();
float readDistance();
int readWaterLevel();
bool getStableDHTReading(float &temp, float &hum);
void postSensorData(const String &eventType, float dist, int water, bool leak, float temp, float hum);
void postRelayState(int state);

void setup() {
  Serial.begin(115200);

  // ← Initialize I2C on GPIO21=D21 (SDA) and GPIO22=D22 (SCL)
  Wire.begin(21, 22);

  // OLED init
  if(!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("SSD1306 allocation failed");
    for(;;);
  }
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(WHITE);
  for (int i = 0; i < 4; i++) {
    lines[i] = {"", i * 8, 0, -1, 0, 1, 0, false};
  }

  // pins
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(LEAK_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW);

  // sensors
  dht.begin();

  // Wi‑Fi
  loadWiFiCredentials();
  connectToWiFi();
}

void loop() {
  // reconnect if dropped
  if (WiFi.status() != WL_CONNECTED) {
    connectToWiFi();
  }

  unsigned long now = millis();

  // read sensors
  float dist = readDistance();
  int waterLevel = readWaterLevel();           // raw analog value
  bool leak = (waterLevel > 1500);             // threshold
  float temp, hum;
  getStableDHTReading(temp, hum);

  // determine presence
  bool presence;
  if (lastDistValue < 0) {
    lastDistValue = dist;
    stableSince = now;
    presence = false;
  } else if (dist != lastDistValue) {
    presence = true;
    lastDistValue = dist;
    stableSince = now;
  } else {
    presence = (now - stableSince) < POST_INTERVAL * 10;
  }

  // relay logic: ON if leaking OR no presence; OFF otherwise
  int newRelay = (leak || !presence) ? HIGH : LOW;
  if (newRelay != lastRelayState) {
    digitalWrite(RELAY_PIN, newRelay);
    postRelayState(newRelay);
    lastRelayState = newRelay;
  }

  // post sensor data every second
  if (now - lastPost >= POST_INTERVAL) {
    String eventType = leak ? "leak_detected"
                       : (!presence ? "waste_alarm" : "presence");
    postSensorData(eventType, dist, waterLevel, leak, temp, hum);
    lastPost = now;
  }

  // prepare display lines
  String L1 = "UltSonic : " + String(dist,1) + " cm";
  String L2 = "Water    : " + String(waterLevel);
  String L3 = "Presence : " + String(presence ? "Detected" : "No");
  String L4 = "Leaking  : " + String(leak ? "Yes" : "No");

  // check for text changes
  String newLines[4] = {L1, L2, L3, L4};
  bool changed = false;
  for (int i = 0; i < 4; i++) {
    if (newLines[i] != lines[i].text) {
      changed = true;
      break;
    }
  }
  if (changed) {
    // recalc scroll ranges
    for (int i = 0; i < 4; i++) {
      lines[i].text = newLines[i];
      int16_t x1, y1; uint16_t w, h;
      display.getTextBounds(lines[i].text, 0, lines[i].y, &x1, &y1, &w, &h);
      lines[i].scrollRange = max(0, (int)w - SCREEN_WIDTH);
      lines[i].offset = 0;
      lines[i].scrollDir = -1;
      lines[i].waiting = false;
    }
    displayDirty = true;
  }

  // redraw/scroll only when dirty
  if (displayDirty && now - lastScrollUpdate >= SCROLL_INTERVAL) {
    lastScrollUpdate = now;
    display.clearDisplay();
    for (int i = 0; i < 4; i++) {
      auto &ln = lines[i];
      if (ln.scrollRange > 0 && !ln.waiting) {
        ln.offset += ln.scrollDir * ln.scrollSpeed;
        if (ln.offset <= -ln.scrollRange || ln.offset >= 0) {
          ln.scrollDir *= -1;
          ln.waiting = true;
          ln.lastReverse = now;
        }
      }
      if (ln.waiting && now - ln.lastReverse > 1000) {
        ln.waiting = false;
      }
      display.setCursor(ln.offset, ln.y);
      display.println(ln.text);
    }
    display.display();
    displayDirty = false;
  }

  delay(1000);
}

// ───── SUPPORT FUNCTIONS ─────

void displayStatus(const String &msg) {
  display.clearDisplay();
  display.setCursor(0,0);
  display.println(msg);
  display.display();
  delay(1000);
}

void loadWiFiCredentials() {
  preferences.begin("wifiCreds", true);
  for (int i = 0; i < MAX_WIFI; i++) {
    ssidList[i] = preferences.getString(("ssid"+String(i)).c_str(), "");
    passList[i] = preferences.getString(("pass"+String(i)).c_str(), "");
  }
  preferences.end();
}

void connectToWiFi() {
  WiFi.disconnect(true);
  WiFi.mode(WIFI_STA);
  delay(100);
  displayStatus("WiFi: Scanning...");
  int n = WiFi.scanNetworks();
  if (n == 0) {
    displayStatus("WiFi: No Network");
    return;
  }
  for (int i = 0; i < MAX_WIFI; i++) {
    if (ssidList[i] == "") continue;
    for (int j = 0; j < n; j++) {
      if (WiFi.SSID(j) == ssidList[i]) {
        WiFi.begin(ssidList[i].c_str(), passList[i].c_str());
        displayStatus("WiFi: Connecting to " + ssidList[i]);
        int tries = 0;
        while (WiFi.status() != WL_CONNECTED && tries < 20) {
          delay(500);
          tries++;
        }
        if (WiFi.status() == WL_CONNECTED) {
          currentSSID = ssidList[i];
          displayStatus("WiFi: " + currentSSID);
          return;
        }
      }
    }
  }
  displayStatus("WiFi: No WiFi");
}

float readDistance() {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  long d = pulseIn(ECHO_PIN, HIGH, 30000);
  return (d * 0.0343) / 2.0;
}

int readWaterLevel() {
  return analogRead(LEAK_PIN);
}

bool getStableDHTReading(float &temp, float &hum) {
  float t, h;
  for (int i = 0; i < 3; i++) {
    t = dht.readTemperature();
    h = dht.readHumidity();
    if (!isnan(t) && !isnan(h)) {
      temp = t; hum = h;
      return true;
    }
    delay(200);
  }
  temp = isnan(t) ? 0 : t;
  hum  = isnan(h) ? 0 : h;
  return false;
}

void postSensorData(const String &eventType, float dist, int water, bool leak, float temp, float hum) {
  HTTPClient http;
  http.begin("https://umairsuhaimee.com/aqua-sentry/api/post_reading.php");
  http.addHeader("Content-Type","application/x-www-form-urlencoded");
  String body = "event_type=" + eventType
              + "&distance="   + String(dist,1)
              + "&leak="       + String(leak?1:0)
              + "&temperature="+ String(temp,1)
              + "&humidity="   + String(hum,1);
  http.POST(body);
  http.end();
}

void postRelayState(int state) {
  HTTPClient http;
  http.begin("https://umairsuhaimee.com/aqua-sentry/api/post_reading.php");
  http.addHeader("Content-Type","application/x-www-form-urlencoded");
  String body = "relay=" + String(state==HIGH?"ON":"OFF");
  http.POST(body);
  http.end();
}
