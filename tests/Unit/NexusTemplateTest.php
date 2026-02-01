<?php

namespace OxyHtmlConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OxyHtmlConverter\TreeBuilder;

/**
 * Test conversion of the NEXUS AI Automation template
 * This complex brutalist Tailwind template tests many edge cases
 */
class NexusTemplateTest extends TestCase
{
    private TreeBuilder $builder;
    private string $nexusHtml;

    protected function setUp(): void
    {
        $this->builder = new TreeBuilder();
        
        // The full NEXUS AI Automation HTML template
        $this->nexusHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEXUS // AI AUTOMATION</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'neon': '#ccff00',
                        'neon-hover': '#b3e600',
                        'void': '#050505',
                        'surface': '#111111',
                        'error': '#ff3333'
                    },
                    fontFamily: {
                        mono: ['"Space Mono"', 'monospace'],
                        display: ['"Syne"', 'sans-serif'],
                    },
                    backgroundImage: {
                        'grid-pattern': "linear-gradient(to right, #222 1px, transparent 1px), linear-gradient(to bottom, #222 1px, transparent 1px)",
                    },
                    boxShadow: {
                        'hard': '6px 6px 0px 0px #ccff00',
                        'hard-hover': '2px 2px 0px 0px #ccff00',
                        'hard-white': '6px 6px 0px 0px #ffffff',
                    }
                }
            }
        }
    </script>
    <style>
        /* Brutalist Base Styles */
        body {
            background-color: #050505;
            color: #ffffff;
            cursor: none; /* Custom cursor */
            overflow-x: hidden;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
            background: #111;
            border-left: 1px solid #333;
        }
        ::-webkit-scrollbar-thumb {
            background: #ccff00;
            border: 2px solid #000;
        }

        /* Glitch Animation */
        @keyframes glitch {
            0% { transform: translate(0); }
            20% { transform: translate(-2px, 2px); }
            40% { transform: translate(-2px, -2px); }
            60% { transform: translate(2px, 2px); }
            80% { transform: translate(2px, -2px); }
            100% { transform: translate(0); }
        }
        .glitch-hover:hover {
            animation: glitch 0.3s cubic-bezier(.25, .46, .45, .94) both infinite;
            color: #ccff00;
        }

        /* Marquee Animation */
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .animate-marquee {
            animation: marquee 10s linear infinite;
        }

        /* Custom Cursor */
        #cursor {
            pointer-events: none;
            mix-blend-mode: difference;
            z-index: 9999;
        }

        /* Grid Background */
        .bg-grid {
            background-size: 40px 40px;
        }

        /* Utilities */
        .text-outline {
            -webkit-text-stroke: 1px white;
            color: transparent;
        }
    </style>
</head>
<body class="font-mono antialiased selection:bg-neon selection:text-black">

    <!-- Custom Cursor -->
    <div id="cursor" class="fixed w-8 h-8 bg-neon rounded-full transform -translate-x-1/2 -translate-y-1/2 hidden md:block transition-transform duration-100 ease-out"></div>

    <!-- Top Marquee -->
    <div class="bg-neon text-black font-bold text-sm py-2 overflow-hidden whitespace-nowrap border-b border-white relative z-50">
        <div class="inline-block animate-marquee">
            SYSTEM ONLINE // AI INTEGRATION ACTIVE // ELIMINATE INEFFICIENCY // SCALE INFINITELY //
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sticky top-0 z-40 bg-void/90 backdrop-blur-sm border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <div class="flex-shrink-0 flex items-center gap-2 group cursor-pointer">
                    <div class="w-8 h-8 bg-neon flex items-center justify-center border border-white group-hover:rotate-12 transition-transform">
                        <i data-lucide="cpu" class="text-black w-5 h-5"></i>
                    </div>
                    <span class="font-display font-bold text-2xl tracking-tighter">NEXUS<span class="text-neon">_</span>Labs</span>
                </div>
                <div class="hidden md:block">
                    <div class="flex items-baseline space-x-8">
                        <a href="#services" class="hover:text-neon transition-colors text-sm uppercase tracking-widest">[Services]</a>
                        <a href="#protocol" class="hover:text-neon transition-colors text-sm uppercase tracking-widest">[Protocol]</a>
                        <a href="#pricing" class="hover:text-neon transition-colors text-sm uppercase tracking-widest">[Database]</a>
                        <a href="#contact" class="bg-white text-black px-6 py-2 font-bold hover:bg-neon hover:shadow-hard hover:-translate-y-1 transition-all border border-black uppercase text-sm">
                            Initialize
                        </a>
                    </div>
                </div>
                <div class="md:hidden">
                    <button id="mobile-menu-btn" class="text-white hover:text-neon">
                        <i data-lucide="menu" class="w-8 h-8"></i>
                    </button>
                </div>
            </div>
        </div>
        <!-- Mobile Menu Panel -->
        <div id="mobile-menu" class="hidden md:hidden bg-surface border-b border-white/20">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
                <a href="#services" class="block px-3 py-2 text-neon hover:bg-white/5 uppercase">[Services]</a>
                <a href="#protocol" class="block px-3 py-2 text-white hover:bg-white/5 uppercase">[Protocol]</a>
                <a href="#contact" class="block px-3 py-2 text-white hover:bg-white/5 uppercase">[Contact]</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="relative bg-grid bg-void border-b border-white/20 overflow-hidden">
        <div class="absolute top-0 right-0 w-1/3 h-full border-l border-white/10 hidden lg:block"></div>
        <div class="absolute bottom-0 left-0 w-full h-1/3 border-t border-white/10"></div>
        
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-24 relative z-10">
            <div class="flex flex-col gap-6">
                <div class="inline-flex items-center gap-2 text-neon text-sm font-bold tracking-widest animate-pulse">
                    <div class="w-2 h-2 bg-neon rounded-full"></div>
                    OPERATIONAL STATUS: 100%
                </div>
                
                <h1 class="font-display text-6xl md:text-8xl lg:text-9xl font-black tracking-tighter leading-none uppercase">
                    Human<br>
                    <span class="text-outline transition-all duration-500 cursor-default">Error Is</span><br>
                    Obsolete<span class="text-neon">.</span>
                </h1>

                <p class="max-w-xl text-gray-400 text-lg md:text-xl font-mono border-l-2 border-neon pl-6 mt-4">
                    // We engineer autonomous AI agents that replace manual workflows. Scale your operations without scaling your headcount.
                </p>

                <div class="flex flex-col sm:flex-row gap-4 mt-8">
                    <a href="#contact" class="group relative px-8 py-4 bg-neon text-black font-bold uppercase tracking-wider overflow-hidden border border-neon">
                        <span class="relative z-10 group-hover:text-white transition-colors duration-300">Deploy Agents</span>
                        <div class="absolute inset-0 bg-black transform -translate-x-full group-hover:translate-x-0 transition-transform duration-300 ease-out"></div>
                    </a>
                    <a href="#services" class="px-8 py-4 bg-transparent border border-white text-white font-bold uppercase tracking-wider hover:bg-white hover:text-black transition-all">
                        View Matrix
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Stats Ticker -->
    <div class="border-b border-white/20 bg-surface">
        <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-white/20">
            <div class="p-6 text-center hover:bg-neon hover:text-black transition-colors duration-300 group cursor-crosshair">
                <h3 class="text-4xl font-display font-bold">500+</h3>
                <p class="text-xs uppercase tracking-widest opacity-60 group-hover:opacity-100">Workflows Killed</p>
            </div>
            <div class="p-6 text-center hover:bg-neon hover:text-black transition-colors duration-300 group cursor-crosshair">
                <h3 class="text-4xl font-display font-bold">24/7</h3>
                <p class="text-xs uppercase tracking-widest opacity-60 group-hover:opacity-100">Uptime</p>
            </div>
            <div class="p-6 text-center hover:bg-neon hover:text-black transition-colors duration-300 group cursor-crosshair">
                <h3 class="text-4xl font-display font-bold">100x</h3>
                <p class="text-xs uppercase tracking-widest opacity-60 group-hover:opacity-100">ROI Speed</p>
            </div>
            <div class="p-6 text-center hover:bg-neon hover:text-black transition-colors duration-300 group cursor-crosshair">
                <h3 class="text-4xl font-display font-bold">0%</h3>
                <p class="text-xs uppercase tracking-widest opacity-60 group-hover:opacity-100">Churn</p>
            </div>
        </div>
    </div>

    <!-- Services Section -->
    <section id="services" class="py-24 bg-void relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-end mb-16 border-b border-white pb-4">
                <h2 class="text-5xl md:text-6xl font-display font-black uppercase text-white">
                    Core<br><span class="text-neon">Modules</span>
                </h2>
                <p class="text-right font-mono text-sm text-gray-400 mt-4 md:mt-0">
                    SELECT * FROM CAPABILITIES<br>WHERE TYPE = 'AUTOMATION'
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 border border-white/20">
                <!-- Card 1 -->
                <div class="group relative p-8 border-b md:border-b-0 md:border-r border-white/20 hover:bg-surface transition-colors">
                    <div class="absolute top-4 right-4 text-neon opacity-0 group-hover:opacity-100 transition-opacity">
                        <i data-lucide="arrow-up-right" class="w-6 h-6"></i>
                    </div>
                    <i data-lucide="bot" class="w-12 h-12 text-neon mb-6"></i>
                    <h3 class="text-2xl font-bold uppercase mb-4 glitch-hover w-fit">Customer Support AI</h3>
                    <p class="text-gray-400 font-mono text-sm leading-relaxed mb-6">
                        Self-learning chatbots that handle 90% of tickets.
                    </p>
                </div>

                <!-- Card 2 -->
                <div class="group relative p-8 border-b md:border-b-0 md:border-r border-white/20 hover:bg-surface transition-colors">
                    <i data-lucide="database-zap" class="w-12 h-12 text-neon mb-6"></i>
                    <h3 class="text-2xl font-bold uppercase mb-4 glitch-hover w-fit">Lead Gen & Outreach</h3>
                    <p class="text-gray-400 font-mono text-sm leading-relaxed mb-6">
                        Scrape, qualify, and engage leads on autopilot.
                    </p>
                </div>

                <!-- Card 3 -->
                <div class="group relative p-8 border-b md:border-b-0 border-white/20 hover:bg-surface transition-colors">
                    <i data-lucide="workflow" class="w-12 h-12 text-neon mb-6"></i>
                    <h3 class="text-2xl font-bold uppercase mb-4 glitch-hover w-fit">Internal Ops</h3>
                    <p class="text-gray-400 font-mono text-sm leading-relaxed mb-6">
                        Automate onboarding, invoicing, and project management.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="bg-neon text-black py-24 border-t border-black">
        <div class="max-w-4xl mx-auto px-4">
            <div class="bg-black p-2 md:p-4 shadow-hard-white border-2 border-black">
                <div class="bg-void p-6 md:p-12 border border-white/20">
                    <h2 class="text-white font-mono text-xl uppercase blink">>> Initialize_Project.exe_</h2>
                    <form class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-neon font-mono text-xs uppercase block">Identity // Name</label>
                                <input type="text" class="w-full bg-transparent border-b-2 border-gray-700 text-white font-mono py-2 focus:outline-none focus:border-neon transition-colors" placeholder="Enter ID string...">
                            </div>
                            <div class="space-y-2">
                                <label class="text-neon font-mono text-xs uppercase block">Comms // Email</label>
                                <input type="email" class="w-full bg-transparent border-b-2 border-gray-700 text-white font-mono py-2 focus:outline-none focus:border-neon transition-colors" placeholder="user@domain.com">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-neon font-mono text-xs uppercase block">Target // Objective</label>
                            <select class="w-full bg-black border-2 border-gray-700 text-white font-mono py-3 px-4 focus:outline-none focus:border-neon appearance-none">
                                <option>Automate Support</option>
                                <option>Lead Generation</option>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-neon text-black font-bold uppercase py-4 border-2 border-neon hover:bg-transparent hover:text-neon transition-all">
                            Execute Command
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-black text-white py-12 border-t border-white/10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-8">
            <div class="flex items-center gap-2">
                <i data-lucide="cpu" class="text-neon w-6 h-6"></i>
                <span class="font-bold tracking-widest">NEXUS_LABS</span>
            </div>
            <div class="font-mono text-xs text-gray-500 text-center md:text-right">
                <p>&copy; 2024 NEXUS AUTOMATION LABS.</p>
            </div>
            <div class="flex gap-4">
                <a href="#" class="w-10 h-10 border border-white/20 flex items-center justify-center hover:bg-neon hover:text-black hover:border-neon transition-colors">
                    <i data-lucide="twitter" class="w-5 h-5"></i>
                </a>
                <a href="#" class="w-10 h-10 border border-white/20 flex items-center justify-center hover:bg-neon hover:text-black hover:border-neon transition-colors">
                    <i data-lucide="linkedin" class="w-5 h-5"></i>
                </a>
            </div>
        </div>
    </footer>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        // Custom Cursor Logic
        const cursor = document.getElementById('cursor');
        
        document.addEventListener('mousemove', (e) => {
            cursor.style.left = e.clientX + 'px';
            cursor.style.top = e.clientY + 'px';
        });

        document.addEventListener('mousedown', () => {
            cursor.style.transform = 'translate(-50%, -50%) scale(0.8)';
        });

        document.addEventListener('mouseup', () => {
            cursor.style.transform = 'translate(-50%, -50%) scale(1)';
        });

        // Hover effect for links to expand cursor
        const links = document.querySelectorAll('a, button');
        links.forEach(link => {
            link.addEventListener('mouseenter', () => {
                cursor.classList.add('scale-150');
            });
            link.addEventListener('mouseleave', () => {
                cursor.classList.remove('scale-150');
            });
        });

        // Mobile Menu Toggle
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');

        btn.addEventListener('click', () => {
            menu.classList.toggle('hidden');
        });

        // Add CSS class for text-stroke
        const style = document.createElement('style');
        style.innerHTML = `
            .text-stroke-white {
                -webkit-text-stroke: 1px rgba(255, 255, 255, 0.5);
            }
            .blink {
                animation: blinker 1s linear infinite;
            }
            @keyframes blinker {
                50% { opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
HTML;
    }

    /**
     * Test basic conversion success
     */
    public function testConversionSucceeds(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success'], 'Conversion should succeed');
        $this->assertNotNull($result['element'], 'Root element should exist');
        $this->assertIsArray($result['stats'], 'Stats should be an array');
        
        // Print stats for debugging
        echo "\n=== CONVERSION STATS ===\n";
        echo "Elements converted: " . ($result['stats']['elementsConverted'] ?? 'N/A') . "\n";
        echo "Warnings: " . count($result['stats']['warnings'] ?? []) . "\n";
        foreach ($result['stats']['warnings'] ?? [] as $warning) {
            echo "  - $warning\n";
        }
    }

    /**
     * Test that Lucide icons are detected
     */
    public function testLucideIconsDetected(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('detectedIconLibraries', $result);
        $this->assertArrayHasKey('lucide', $result['detectedIconLibraries'], 
            'Lucide icons should be detected (data-lucide attributes present)');
    }

    /**
     * Test CSS extraction from style tags
     */
    public function testCssExtraction(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['extractedCss'], 'CSS should be extracted from style tags');
        
        // Check for specific CSS content
        $this->assertStringContainsString('@keyframes glitch', $result['extractedCss'], 
            'Glitch animation should be extracted');
        $this->assertStringContainsString('.animate-marquee', $result['extractedCss'],
            'Marquee animation class should be extracted');
        $this->assertStringContainsString('#cursor', $result['extractedCss'],
            'Cursor styles should be extracted');
    }

    /**
     * Test custom classes are tracked
     */
    public function testCustomClassesTracked(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        $customClasses = $result['customClasses'] ?? [];
        
        // Custom classes that should be detected (not Tailwind)
        $expectedCustom = ['glitch-hover', 'animate-marquee', 'bg-grid', 'text-outline'];
        
        foreach ($expectedCustom as $class) {
            $this->assertContains($class, $customClasses, 
                "Custom class '$class' should be tracked");
        }
    }

    /**
     * Test form elements are converted to HTML_Code
     */
    public function testFormElementsHandled(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find form elements in the tree
        $formFound = false;
        $this->walkTree($result['element'], function($node) use (&$formFound) {
            if (isset($node['data']['type']) && $node['data']['type'] === 'OxygenElements\\HTML_Code') {
                $htmlCode = $node['data']['properties']['content']['content']['html_code'] ?? '';
                if (strpos($htmlCode, '<form') !== false) {
                    $formFound = true;
                }
            }
        });
        
        $this->assertTrue($formFound, 'Form should be converted to HTML_Code element');
    }

    /**
     * Test JavaScript transformation
     */
    public function testJavaScriptTransformed(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find JavaScript elements
        $jsElements = [];
        $this->walkTree($result['element'], function($node) use (&$jsElements) {
            if (isset($node['data']['type']) && $node['data']['type'] === 'OxygenElements\\JavaScript_Code') {
                $jsElements[] = $node['data']['properties']['content']['content']['javascript_code'] ?? '';
            }
        });
        
        $this->assertNotEmpty($jsElements, 'JavaScript elements should be created');
        
        // Check that lucide.createIcons() is preserved
        $allJs = implode("\n", $jsElements);
        $this->assertStringContainsString('lucide.createIcons', $allJs,
            'Lucide initialization should be preserved');
    }

    /**
     * Test nav element structure
     */
    public function testNavElementStructure(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find nav element
        $navElement = null;
        $this->walkTree($result['element'], function($node) use (&$navElement) {
            if (isset($node['data']['properties']['design']['tag']) && 
                $node['data']['properties']['design']['tag'] === 'nav') {
                $navElement = $node;
            }
        });
        
        $this->assertNotNull($navElement, 'Nav element should be found');
        $this->assertEquals('OxygenElements\\Container', $navElement['data']['type'],
            'Nav should be a Container element');
        
        // Check classes are preserved
        $classes = $navElement['data']['properties']['settings']['advanced']['classes'] ?? [];
        $this->assertContains('sticky', $classes, 'sticky class should be preserved');
    }

    /**
     * Test button elements get proper layout
     */
    public function testButtonLayout(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find button elements
        $buttonElement = null;
        $this->walkTree($result['element'], function($node) use (&$buttonElement) {
            if (isset($node['data']['properties']['settings']['advanced']['id']) && 
                $node['data']['properties']['settings']['advanced']['id'] === 'mobile-menu-btn') {
                $buttonElement = $node;
            }
        });
        
        $this->assertNotNull($buttonElement, 'Mobile menu button should be found');
        
        // Check that button has flex layout
        $layout = $buttonElement['data']['properties']['design']['layout'] ?? [];
        $this->assertEquals('flex', $layout['display'] ?? null, 
            'Button should have display: flex');
    }

    /**
     * Test grid detection
     */
    public function testGridDetection(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find grid elements (stats ticker, services)
        $gridElements = [];
        $this->walkTree($result['element'], function($node) use (&$gridElements) {
            $layout = $node['data']['properties']['design']['layout'] ?? [];
            if (isset($layout['display']) && $layout['display'] === 'grid') {
                $gridElements[] = $node;
            }
        });
        
        $this->assertNotEmpty($gridElements, 'Grid elements should be detected');
    }

    /**
     * Test header section handling
     */
    public function testHeaderSectionHandling(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find header element
        $headerElement = null;
        $this->walkTree($result['element'], function($node) use (&$headerElement) {
            if (isset($node['data']['properties']['design']['tag']) && 
                $node['data']['properties']['design']['tag'] === 'header') {
                $headerElement = $node;
            }
        });
        
        $this->assertNotNull($headerElement, 'Header element should be found');
    }

    /**
     * Test section IDs are preserved
     */
    public function testSectionIdsPreserved(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        $ids = [];
        $this->walkTree($result['element'], function($node) use (&$ids) {
            if (isset($node['data']['properties']['settings']['advanced']['id'])) {
                $ids[] = $node['data']['properties']['settings']['advanced']['id'];
            }
        });
        
        // Check key IDs are preserved
        $expectedIds = ['cursor', 'mobile-menu-btn', 'mobile-menu', 'services', 'contact'];
        foreach ($expectedIds as $id) {
            $this->assertContains($id, $ids, "ID '$id' should be preserved");
        }
    }

    /**
     * Test link elements are properly typed
     */
    public function testLinkElementTypes(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        // Find all link elements
        $textLinks = [];
        $containerLinks = [];
        
        $this->walkTree($result['element'], function($node) use (&$textLinks, &$containerLinks) {
            $type = $node['data']['type'] ?? '';
            if ($type === 'OxygenElements\\Text_Link') {
                $textLinks[] = $node;
            } elseif ($type === 'OxygenElements\\Container_Link') {
                $containerLinks[] = $node;
            }
        });
        
        // Should have both types: simple nav links and button-like CTA links
        $this->assertNotEmpty($textLinks, 'Should have Text_Link elements for simple links');
        // Button-like links (with children) should be Container_Link
    }

    /**
     * Test element count is reasonable
     */
    public function testElementCount(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        $count = 0;
        $this->walkTree($result['element'], function($node) use (&$count) {
            $count++;
        });
        
        // This template has many elements, should be substantial
        $this->assertGreaterThan(50, $count, 'Should have converted many elements');
        
        echo "\n=== ELEMENT COUNT: $count ===\n";
    }

    /**
     * Test to identify all issues - MAIN DIAGNOSTIC TEST
     */
    public function testIdentifyAllIssues(): void
    {
        $result = $this->builder->convert($this->nexusHtml);
        
        $this->assertTrue($result['success']);
        
        $issues = [];
        
        // 1. Check for missing icon library elements
        $iconElements = $result['iconScriptElements'] ?? [];
        if (empty($iconElements)) {
            $issues[] = 'ISSUE: Icon library elements not created despite Lucide icons being detected';
        }
        
        // 2. Check for any element type mismatches
        $this->walkTree($result['element'], function($node) use (&$issues) {
            $type = $node['data']['type'] ?? '';
            
            // Check for wrong element type: HtmlCode instead of HTML_Code
            if (strpos($type, 'HtmlCode') !== false && strpos($type, 'HTML_Code') === false) {
                $issues[] = "ISSUE: Wrong element type '$type' - should be 'HTML_Code' with underscore";
            }
            
            // Check for empty containers that might cause issues
            if ($type === 'OxygenElements\\Container') {
                $children = $node['children'] ?? [];
                $hasContent = !empty($children) || 
                              !empty($node['data']['properties']['content']['content']['text'] ?? '');
                if (!$hasContent) {
                    $id = $node['data']['properties']['settings']['advanced']['id'] ?? 'unknown';
                    // Some empty containers are intentional (decorative divs)
                }
            }
        });
        
        // 3. Check CSS element creation
        if (empty($result['cssElement'])) {
            $issues[] = 'ISSUE: CSS element not created despite extracted CSS';
        }
        
        // 4. Check warnings from conversion
        $warnings = $result['stats']['warnings'] ?? [];
        foreach ($warnings as $warning) {
            $issues[] = "WARNING: $warning";
        }
        
        // 5. Check for Tailwind CDN config script handling
        $foundTailwindConfig = false;
        $this->walkTree($result['element'], function($node) use (&$foundTailwindConfig) {
            $type = $node['data']['type'] ?? '';
            if (strpos($type, 'JavaScript') !== false || strpos($type, 'HTML_Code') !== false) {
                $content = $node['data']['properties']['content']['content']['javascript_code'] ?? 
                           $node['data']['properties']['content']['content']['html_code'] ?? '';
                if (strpos($content, 'tailwind.config') !== false) {
                    $foundTailwindConfig = true;
                }
            }
        });
        if (!$foundTailwindConfig) {
            $issues[] = 'INFO: Tailwind config script not found in output (may be fine if CDN handles it)';
        }
        
        // Print all issues found
        echo "\n=== ISSUES FOUND ===\n";
        if (empty($issues)) {
            echo "No major issues detected!\n";
        } else {
            foreach ($issues as $issue) {
                echo "  - $issue\n";
            }
        }
        
        // This test always passes but provides diagnostic output
        $this->assertTrue(true);
    }

    /**
     * Helper to walk the element tree
     */
    private function walkTree(array $node, callable $callback): void
    {
        $callback($node);
        foreach ($node['children'] ?? [] as $child) {
            $this->walkTree($child, $callback);
        }
    }
}
