<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Threshold;
use App\Models\DailyCount;
use App\Models\Trip;
use Jenssegers\Agent\Agent;

class ThresholdService
{
    protected $telegramBotToken;
    protected $telegramChatId;
    protected $agent;

    public function __construct()
    {
        $this->telegramBotToken = env('TELEGRAM_BOT_TOKEN');
        $this->telegramChatId = config('PERSONAL_CHAT_ID');
        $this->agent = new Agent();
    }

    public function checkAndValidateTripLimits($wallet, $ip)
    {
        DB::beginTransaction();
        try {

            if (!$this->isMobileDevice()) {
                $this->sendTelegramAlert(
                    "Non-Mobile Request Detected",
                    "Request from IP {$ip} rejected: Not from Android or iOS device. User-Agent: {$this->agent->getUserAgent()}"
                );
                throw new \Exception("Request must originate from an Android or iOS mobile device");
            }
            // Get city from IP
            $city = $this->getCityFromIp($ip);

            // Check user limit
            $userThreshold = Threshold::getThreshold('user');
            if ($userThreshold) {
                $userCount = Trip::where('wallet', $wallet)
                    ->whereDate('created_at', now()->toDateString())
                    ->count();
                if ($userCount >= $userThreshold->threshold) {
                    $this->sendTelegramAlert(
                        "User Threshold Exceeded",
                        "User ID {$wallet} has reached trip threshold of {$userThreshold->threshold}"
                    );
                    throw new \Exception("User trip threshold exceeded");
                }
            }

            // Check city limit
            if ($city) {
                $cityThreshold = Threshold::getThreshold('city');
                if ($cityThreshold) {
                    $cityCount = DailyCount::getCount('city', $city);
                    if ($cityCount && $cityCount->count >= $cityThreshold->threshold) {
                        $this->sendTelegramAlert(
                            "City Threshold Exceeded",
                            "City {$city} has reached trip threshold of {$cityThreshold->threshold}"
                        );
                        throw new \Exception("City trip threshold exceeded");
                    }
                }
            }

            // Check system limit
            $systemThreshold = Threshold::getThreshold('system');
            if ($systemThreshold) {
                $systemCount = Trip::whereDate('created_at', now()->toDateString())->count();
                if ($systemCount >= $systemThreshold->threshold) {
                    $this->sendTelegramAlert(
                        "System Threshold Exceeded",
                        "System daily trip threshold of {$systemThreshold->threshold} has been reached"
                    );
                    throw new \Exception("System daily trip threshold exceeded");
                }
            }

            // Increment counts
            DailyCount::incrementCount('wallet', $wallet);
            if ($city) {
                DailyCount::incrementCount('city', $city);
            }

            DB::commit();
            return $city ?: null;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function isMobileDevice()
    {
        return $this->agent->isMobile() && ($this->agent->is('AndroidOS') || $this->agent->is('iOS'));
    }

    protected function getCityFromIp($ip)
    {
        try {
            $response = Http::get("https://ipapi.co/{$ip}/json/");
            if ($response->successful()) {
                $data = $response->json();
                return $data['city'] ?? null;
            }
            return null;
        } catch (\Exception $e) {
            \Log::warning("Failed to get city from IP: {$e->getMessage()}");
            return null;
        }
    }

    protected function sendTelegramAlert($title, $message)
    {
        Http::post("https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage", [
            'chat_id' => $this->telegramChatId,
            'text' => "🚨 {$title}\n{$message}\nTime: " . now()->toDateTimeString(),
        ]);
    }
}