<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotRecommendationTest extends TestCase
{
    public function test_recommendation_validation_flow()
    {
        // Mock scholarship data in session
        $s = [
            'id' => 999,
            'nama_beasiswa' => 'Beasiswa Test',
            'persyaratan' => 'Wajib melampirkan surat rekomendasi dari dekan.',
            'deskripsi' => 'Deskripsi beasiswa test',
            'benefit' => 'Uang saku bulanan'
        ];

        // Put selected scholarship in session
        $sessionData = ['selected_scholarship' => $s];

        // Ask validation question
        $response = $this->withSession($sessionData)->postJson('/chatbot/ask', [
            'message' => 'Apakah beasiswa ini wajib punya surat rekomendasi?',
            'rag_enabled' => true
        ]);

        $response->assertStatus(200);
        $content = $response->json();
        
        $this->assertTrue($content['success']);
        // The answer should confirm that it requires recommendation
        $this->assertStringContainsString('mensyaratkan', $content['answer']);
        $this->assertStringContainsString('Rekomendasi', $content['answer']);
    }

    public function test_toefl_score_detail_flow()
    {
        // Mock scholarship data in session
        $s = [
            'id' => 999,
            'nama_beasiswa' => 'Beasiswa Test',
            'persyaratan' => 'Persyaratan: Minimal IELTS 6.5 atau TOEFL iBT 90.',
            'deskripsi' => 'Deskripsi beasiswa test',
            'benefit' => 'Uang saku bulanan'
        ];

        // Put selected scholarship in session
        $sessionData = ['selected_scholarship' => $s];

        // Ask TOEFL detail question
        $response = $this->withSession($sessionData)->postJson('/chatbot/ask', [
            'message' => 'skor toefl nya harus berapa',
            'rag_enabled' => true
        ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertTrue($content['success']);
        // The answer should display the requirements, not the benefits
        $this->assertStringContainsString('Persyaratan', $content['answer']);
        $this->assertStringContainsString('TOEFL', $content['answer']);
    }
}
